<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 fix order 1, L3 — a product type change never leaves a stale
 * recipe behind, and changes that switch recipe deduction need "Edit recipes".
 *
 *  - Leaving a recipe type (made-to-order / cooked → bought-in / untracked)
 *    clears the recipe ON THE SERVER through the recipe action: a version row
 *    and a recipe audit row name the actor, so the history shows it. Before,
 *    the wizard skipped the recipe PUT and the rows stayed: the badge, Recipe
 *    & Cost and the cost fallback kept using them, and switching back made
 *    them live again.
 *  - Owner decision 2026-10-02: a stock-mode change into or out of a recipe
 *    type on a product WITH recipe rows, and an add-on's linked-product
 *    change, need "Edit recipes" (+ catalogue.view). The default Inventory
 *    Manager (catalogue.manage only) is refused; the rest of the product
 *    stays editable for it.
 */

use App\Enums\MerchantRole;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** A made-to-order latte with one recipe line (milk 180 ml), written at the table level. */
function l3Latte(array $ctx, string $mode = 'ingredient'): Product
{
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0005');
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'stock_mode' => $mode]);
    ProductRecipe::query()->create(['product_id' => $latte->id, 'ingredient_id' => $milk->id, 'quantity' => '180', 'unit_at_set' => 'ml']);

    return $latte;
}

it('clears the recipe on the server when a dish leaves a recipe type, with a version and audit row naming the actor', function (string $to): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $latte = l3Latte($ctx);

    $this->patchJson("/api/products/{$latte->uuid}", ['stock_mode' => $to])->assertOk();

    expect(ProductRecipe::query()->where('product_id', $latte->id)->count())->toBe(0)
        ->and($latte->fresh()->stock_mode)->toBe($to);
    $version = ProductRecipeVersion::query()->where('product_id', $latte->id)->sole();
    expect((int) $version->edited_by_user_id)->toBe((int) $ctx['user']->id)
        ->and($version->note)->toStartWith('Recipe removed: the product type changed from made to order to')
        ->and(collect($version->recipe_json)->pluck('quantity')->map(fn ($q) => (float) $q)->all())->toBe([180.0]);
    $audit = DB::table('pos_audit_logs')->where('event', 'catalogue.product.recipe_updated')->where('auditable_id', $latte->id)->sole();
    expect((int) $audit->actor_user_id)->toBe((int) $ctx['user']->id);

    // The recipe history shows the removal; switching back brings nothing back to life.
    $change = $this->getJson("/api/products/{$latte->uuid}/recipe-history")->assertOk()->json('data.versions.0.changes.0');
    expect($change['change'])->toBe('removed')->and($change['before'])->toBe('180 ml');
    $this->patchJson("/api/products/{$latte->uuid}", ['stock_mode' => 'ingredient'])->assertOk();
    expect(ProductRecipe::query()->where('product_id', $latte->id)->count())->toBe(0);
})->with(['untracked', 'unit']);

it('keeps the recipe when a dish moves between made-to-order and cooked', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $latte = l3Latte($ctx);

    $this->patchJson("/api/products/{$latte->uuid}", ['stock_mode' => 'cooked'])->assertOk();

    expect(ProductRecipe::query()->where('product_id', $latte->id)->count())->toBe(1)
        ->and(ProductRecipeVersion::query()->count())->toBe(0);
});

it('refuses a stock-mode change that switches recipe deduction without "Edit recipes" (the default Inventory Manager)', function (string $from, string $to): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $latte = l3Latte($ctx, $from);

    $this->patchJson("/api/products/{$latte->uuid}", ['stock_mode' => $to])->assertForbidden();

    expect($latte->fresh()->stock_mode)->toBe($from)
        ->and(ProductRecipe::query()->where('product_id', $latte->id)->count())->toBe(1)
        ->and(ProductRecipeVersion::query()->count())->toBe(0);

    // The rest of the product stays theirs to edit.
    $this->patchJson("/api/products/{$latte->uuid}", ['name' => 'Flat white', 'base_price' => '1.800'])->assertOk();
    expect($latte->fresh()->name)->toBe('Flat white');
})->with([
    'made to order → untracked' => ['ingredient', 'untracked'],
    'made to order → cooked' => ['ingredient', 'cooked'],
    'cooked → bought-in' => ['cooked', 'unit'],
    'stale recipe on an untracked product → made to order' => ['untracked', 'ingredient'],
]);

it('lets the Inventory Manager change the type of a product without a recipe', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $water = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Water', 'stock_mode' => 'untracked']);

    $this->patchJson("/api/products/{$water->uuid}", ['stock_mode' => 'unit'])->assertOk();
    $this->patchJson("/api/products/{$water->uuid}", ['stock_mode' => 'ingredient'])->assertOk();
    expect($water->fresh()->stock_mode)->toBe('ingredient');
});

it('needs "Edit recipes" to link, re-link or unlink an add-on\'s product', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $latte = l3Latte($ctx);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'cooked']);
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Sides']);
    // Creating an option linked to a product is a new option, not a change to a deduction.
    $uuid = $this->postJson("/api/addon-groups/{$group->uuid}/addons", ['name' => 'Latte', 'linked_product_uuid' => $latte->uuid])
        ->assertCreated()->json('data.uuid');

    $this->patchJson("/api/addons/{$uuid}", ['linked_product_uuid' => $cake->uuid])->assertForbidden();
    $this->patchJson("/api/addons/{$uuid}", ['linked_product_uuid' => null])->assertForbidden();
    expect((int) AddOn::query()->where('uuid', $uuid)->value('linked_product_id'))->toBe((int) $latte->id);
    // An unchanged link with a price change is fine.
    $this->patchJson("/api/addons/{$uuid}", ['linked_product_uuid' => $latte->uuid, 'price_delta' => '0.900'])->assertOk();

    $manager = makeMerchantActor(MerchantRole::Manager->value);
    $mLatte = l3Latte($manager);
    $mCake = Product::factory()->for($manager['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'cooked']);
    $mGroup = AddOnGroup::factory()->for($manager['company'], 'company')->create(['name' => 'Sides']);
    $mUuid = $this->postJson("/api/addon-groups/{$mGroup->uuid}/addons", ['name' => 'Latte', 'linked_product_uuid' => $mLatte->uuid])
        ->assertCreated()->json('data.uuid');
    $this->patchJson("/api/addons/{$mUuid}", ['linked_product_uuid' => $mCake->uuid])->assertOk();
    expect((int) AddOn::query()->where('uuid', $mUuid)->value('linked_product_id'))->toBe((int) $mCake->id);
});
