<?php

declare(strict_types=1);

/**
 * PD1 — the 3-step product wizard's atomic create + the single-product
 * read its edit mode loads from.
 *
 *   POST /api/products/wizard       product + shared groups + inline
 *                                   owned groups w/ options + recipe +
 *                                   physical items + branches + provider
 *                                   prices, ALL-OR-NOTHING.
 *   GET  /api/products/{uuid}       full prefill payload for edit mode.
 *
 * The rollback tests are the point: the old modal's 6-call chain could
 * strand a half-configured product; the wizard endpoint cannot.
 */

use App\Enums\MerchantRole;
use App\Models\AddOnGroup;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DeliveryProvider;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A minimal valid wizard payload; override per test. */
function wizardPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'product' => [
            'name' => 'Wizard Latte',
            'base_price' => '1.500',
            'stock_mode' => 'ingredient',
        ],
        'addon_group_uuids' => [],
        'owned_groups' => [],
        'recipe_lines' => [],
        'component_lines' => [],
        'branches' => null,
        'delivery_prices' => [],
    ], $overrides);
}

it('creates a fully configured product atomically', function (): void {
    $ctx = makeMerchantActor();
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    $shared = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['is_global' => false, 'name' => 'Syrups']);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $cup = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'is_internal' => true, 'name' => 'Cup 12oz']);
    $shot = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'name' => 'Espresso Shot']);
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();

    $res = $this->postJson('/api/products/wizard', wizardPayload([
        'product' => [
            'category_id' => $category->id,
            'sku' => 'WZ-1',
            'available_from' => '08:00',
            'available_until' => '14:00',
        ],
        'addon_group_uuids' => [$shared->uuid],
        'owned_groups' => [[
            'name' => 'Latte size',
            'selection_mode' => 'single',
            'min_selections' => 1,
            'max_selections' => 1,
            'options' => [
                ['name' => 'Small', 'price_delta' => '0', 'is_default' => true],
                ['name' => 'Extra shot', 'price_delta' => '0.300', 'linked_product_uuid' => $shot->uuid],
            ],
        ]],
        'recipe_lines' => [['ingredient_uuid' => $ingredient->uuid, 'quantity' => 18]],
        'component_lines' => [['component_uuid' => $cup->uuid, 'quantity' => 1]],
        'branches' => [
            ['branch_id' => $ctx['branch']->id, 'is_available' => true, 'stock_qty' => null],
            ['branch_id' => $branchB->id, 'is_available' => false, 'stock_qty' => null],
        ],
        'delivery_prices' => [['provider_uuid' => $provider->uuid, 'price' => '2.000']],
    ]))->assertCreated();

    $product = Product::query()->where('name', 'Wizard Latte')->firstOrFail();
    expect($product->sku)->toBe('WZ-1')
        ->and($product->category_id)->toBe($category->id);

    // Shared group attached; owned group created, attached, with both options.
    expect($product->addOnGroups()->pluck('pos_addon_groups.id')->all())->toContain($shared->id);
    $owned = AddOnGroup::query()->where('owner_product_id', $product->id)->firstOrFail();
    expect($owned->is_global)->toBeFalse()
        ->and($owned->min_selections)->toBe(1)
        ->and($owned->addOns()->count())->toBe(2);
    $linkedOption = $owned->addOns()->where('name', 'Extra shot')->firstOrFail();
    expect($linkedOption->linked_product_id)->toBe($shot->id);
    expect($owned->addOns()->where('name', 'Small')->firstOrFail()->is_default)->toBeTrue();

    // Recipe, components, branches, delivery price all landed.
    expect(DB::table('pos_product_recipes')->where('product_id', $product->id)->count())->toBe(1);
    expect(DB::table('pos_product_components')->where('product_id', $product->id)->where('component_product_id', $cup->id)->exists())->toBeTrue();
    expect(DB::table('pos_branch_product')->where('product_id', $product->id)->count())->toBe(2);
    expect(DB::table('pos_product_delivery_prices')->where('product_id', $product->id)->exists())->toBeTrue();

    // The 201 response carries the full nested shape for the review step.
    expect($res->json('data.addon_groups'))->toHaveCount(2)
        ->and($res->json('data.recipe_lines'))->toHaveCount(1)
        ->and($res->json('data.component_lines'))->toHaveCount(1)
        ->and($res->json('data.delivery_provider_prices'))->toHaveCount(1)
        ->and($res->json('data.available_from'))->toContain('08:00');
});

it('rolls the whole product back when a nested option is invalid', function (): void {
    $ctx = makeMerchantActor();
    $foreignProduct = Product::factory()->for(Company::factory()->create(), 'company')->create();

    $res = $this->postJson('/api/products/wizard', wizardPayload([
        'owned_groups' => [[
            'name' => 'Broken group',
            'options' => [['name' => 'Bad link', 'linked_product_uuid' => $foreignProduct->uuid]],
        ]],
    ]));

    $res->assertUnprocessable();
    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse()
        ->and(AddOnGroup::query()->where('name', 'Broken group')->exists())->toBeFalse();
});

it('rolls back on a foreign delivery provider too', function (): void {
    $ctx = makeMerchantActor();
    $foreignProvider = DeliveryProvider::factory()->for(Company::factory()->create(), 'company')->create();

    $this->postJson('/api/products/wizard', wizardPayload([
        'delivery_prices' => [['provider_uuid' => $foreignProvider->uuid, 'price' => '2.000']],
    ]))->assertUnprocessable();

    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse();
});

it('refuses a recipe on ready / bought-in and untracked products', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    foreach (['unit', 'untracked'] as $mode) {
        $this->postJson('/api/products/wizard', wizardPayload([
            'product' => ['stock_mode' => $mode],
            'recipe_lines' => [['ingredient_uuid' => $ingredient->uuid, 'quantity' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['recipe_lines']);
    }

    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse();
});

it('rejects duplicate skus and owned-group name collisions with field errors', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create(['sku' => 'TAKEN']);
    AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Existing group']);

    $this->postJson('/api/products/wizard', wizardPayload([
        'product' => ['sku' => 'TAKEN'],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['product.sku']);

    // Collides with an existing shared group (hard DB unique has no owner carve-out).
    $this->postJson('/api/products/wizard', wizardPayload([
        'owned_groups' => [['name' => 'Existing group', 'options' => []]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['owned_groups.0.name']);

    // Used twice within the same payload.
    $this->postJson('/api/products/wizard', wizardPayload([
        'owned_groups' => [
            ['name' => 'Size', 'options' => []],
            ['name' => 'Size', 'options' => []],
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['owned_groups.1.name']);

    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse();
});

it('enforces the owned-group min/max cross-field rules', function (): void {
    $ctx = makeMerchantActor();

    $this->postJson('/api/products/wizard', wizardPayload([
        'owned_groups' => [['name' => 'Size', 'min_selections' => 3, 'max_selections' => 2, 'options' => []]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['owned_groups.0.max_selections']);

    // A single-choice group holding min 2 would brick the POS Apply
    // button (documented production incident on the standalone endpoint).
    $this->postJson('/api/products/wizard', wizardPayload([
        'owned_groups' => [['name' => 'Size', 'selection_mode' => 'single', 'min_selections' => 2, 'options' => []]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['owned_groups.0.min_selections']);

    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse();
});

it('refuses a name still held by a soft-deleted group with a clean 422', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Sauces']);
    $group->delete();

    // The DB unique index has no deleted_at carve-out — without the
    // withTrashed check this would trip the index mid-transaction.
    $this->postJson('/api/products/wizard', wizardPayload([
        'owned_groups' => [['name' => 'Sauces', 'options' => []]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['owned_groups.0.name']);

    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse();
});

it('pins the branches [] boundary on both sides of the F5 guard', function (): void {
    // A scoped user's explicit empty array is still a branch payload → 403.
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $this->postJson('/api/products/wizard', wizardPayload(['branches' => []]))->assertForbidden();
    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse();

    // An unrestricted user's [] = explicit every-branch sync → created, zero rows.
    makeMerchantActor();
    $this->postJson('/api/products/wizard', wizardPayload(['branches' => []]))->assertCreated();
    $product = Product::query()->where('name', 'Wizard Latte')->firstOrFail();
    expect(DB::table('pos_branch_product')->where('product_id', $product->id)->count())->toBe(0);
});

it('keeps branch assignment HQ-only but lets scoped users create without it', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();

    $this->postJson('/api/products/wizard', wizardPayload([
        'branches' => [['branch_id' => $ctx['branch']->id, 'is_available' => true, 'stock_qty' => null]],
    ]))->assertForbidden();
    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse();

    // branches: null = skip the sync — fine for a scoped user.
    $this->postJson('/api/products/wizard', wizardPayload())->assertCreated();
});

it('gates the wizard on catalogue.manage', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);

    $this->postJson('/api/products/wizard', wizardPayload())->assertForbidden();
    expect(Product::query()->where('name', 'Wizard Latte')->exists())->toBeFalse();
});

it('serves the single-product read for edit mode and 404s foreign tenants', function (): void {
    $ctx = makeMerchantActor();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Editable']);
    $this->putJson("/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}", ['price' => '9.000'])->assertCreated();

    $res = $this->getJson("/api/products/{$product->uuid}")->assertOk();
    expect($res->json('data.uuid'))->toBe($product->uuid)
        ->and($res->json('data.name'))->toBe('Editable')
        ->and($res->json('data.delivery_provider_prices'))->toHaveCount(1)
        ->and($res->json('data'))->toHaveKeys(['recipe_lines', 'component_lines', 'branches', 'addon_groups']);

    // The literal picker routes must still win over the uuid binding.
    $this->getJson('/api/products/component-options')->assertOk();

    $foreign = Product::factory()->for(Company::factory()->create(), 'company')->create();
    $this->getJson("/api/products/{$foreign->uuid}")->assertNotFound();
});
