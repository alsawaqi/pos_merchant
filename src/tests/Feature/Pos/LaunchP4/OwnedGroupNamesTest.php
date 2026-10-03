<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 M4 — add-on group names are unique where the new pos_admin
 * indexes say (2026_10_03_100009): a product's own group is unique per
 * owner product, a shared group per company among shared groups. Soft-
 * deleted groups still hold their name. Before: one company-wide name
 * rule, so two products could not each own a "Size" group.
 */

use App\Models\AddOnGroup;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

if (! function_exists('p4M4WizardPayload')) {
    /**
     * @param  array<int, array<string, mixed>>  $ownedGroups
     * @return array<string, mixed>
     */
    function p4M4WizardPayload(string $name, array $ownedGroups): array
    {
        return [
            'product' => [
                'name' => $name,
                'base_price' => '1.500',
                'stock_mode' => 'untracked',
            ],
            'addon_group_uuids' => [],
            'owned_groups' => $ownedGroups,
            'recipe_lines' => [],
            'component_lines' => [],
            'branches' => null,
            'delivery_prices' => [],
        ];
    }
}

it('saves two products that each own a Size group through the wizard', function (): void {
    $ctx = makeMerchantActor();
    $size = [
        'name' => 'Size',
        'selection_mode' => 'single',
        'options' => [['name' => 'Small', 'price_delta' => '0'], ['name' => 'Large', 'price_delta' => '0.500']],
    ];

    $this->postJson('/api/products/wizard', p4M4WizardPayload('Latte', [$size]))->assertCreated();
    $this->postJson('/api/products/wizard', p4M4WizardPayload('Mocha', [$size]))->assertCreated();

    $latte = Product::query()->where('name', 'Latte')->firstOrFail();
    $mocha = Product::query()->where('name', 'Mocha')->firstOrFail();
    $owners = AddOnGroup::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Size')
        ->pluck('owner_product_id')
        ->map(fn ($id): int => (int) $id)
        ->sort()
        ->values()
        ->all();
    expect($owners)->toBe(collect([$latte->id, $mocha->id])->sort()->values()->all());
});

it('lets a wizard product own a group named like a shared group', function (): void {
    $ctx = makeMerchantActor();
    AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Size']);

    $this->postJson('/api/products/wizard', p4M4WizardPayload('Latte', [['name' => 'Size', 'options' => []]]))
        ->assertCreated();

    expect(AddOnGroup::query()->where('name', 'Size')->count())->toBe(2);
});

it('still refuses the same group name twice in one wizard form', function (): void {
    makeMerchantActor();

    $this->postJson('/api/products/wizard', p4M4WizardPayload('Latte', [
        ['name' => 'Size', 'options' => []],
        ['name' => 'Size', 'options' => []],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['owned_groups.1.name']);

    expect(Product::query()->where('name', 'Latte')->exists())->toBeFalse();
});

it('lets two products each own a Size group through the product add-on screen', function (): void {
    $ctx = makeMerchantActor();
    $a = Product::factory()->for($ctx['company'], 'company')->create();
    $b = Product::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/products/{$a->uuid}/addon-groups", ['name' => 'Size'])->assertCreated();
    $this->postJson("/api/products/{$b->uuid}/addon-groups", ['name' => 'Size'])->assertCreated();

    // Same product twice is refused with a clean 422.
    $this->postJson("/api/products/{$a->uuid}/addon-groups", ['name' => 'Size'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('keeps a deleted owned group name taken for that product only', function (): void {
    $ctx = makeMerchantActor();
    $a = Product::factory()->for($ctx['company'], 'company')->create();
    $b = Product::factory()->for($ctx['company'], 'company')->create();
    $gone = $this->postJson("/api/products/{$a->uuid}/addon-groups", ['name' => 'Milk'])->assertCreated()->json('data');
    $this->deleteJson("/api/addon-groups/{$gone['uuid']}")->assertNoContent();

    $this->postJson("/api/products/{$a->uuid}/addon-groups", ['name' => 'Milk'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson("/api/products/{$b->uuid}/addon-groups", ['name' => 'Milk'])->assertCreated();
});

it('checks shared group names only against shared groups', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $this->postJson("/api/products/{$product->uuid}/addon-groups", ['name' => 'Size'])->assertCreated();

    // A product's own "Size" does not block a shared "Size"...
    $this->postJson('/api/addon-groups', ['name' => 'Size'])->assertCreated();
    // ...but a second shared "Size" is refused.
    $this->postJson('/api/addon-groups', ['name' => 'Size'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);

    // A deleted shared group still holds its name.
    $old = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Sauces']);
    $old->delete();
    $this->postJson('/api/addon-groups', ['name' => 'Sauces'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('renames an owned group by the per-product rule, deleted groups included', function (): void {
    $ctx = makeMerchantActor();
    $a = Product::factory()->for($ctx['company'], 'company')->create();
    $b = Product::factory()->for($ctx['company'], 'company')->create();
    $this->postJson("/api/products/{$b->uuid}/addon-groups", ['name' => 'Size'])->assertCreated();
    $own = $this->postJson("/api/products/{$a->uuid}/addon-groups", ['name' => 'Cup'])->assertCreated()->json('data');
    $gone = $this->postJson("/api/products/{$a->uuid}/addon-groups", ['name' => 'Old size'])->assertCreated()->json('data');
    $this->deleteJson("/api/addon-groups/{$gone['uuid']}")->assertNoContent();

    // Another product's "Size" does not block this product's rename.
    $this->patchJson("/api/addon-groups/{$own['uuid']}", ['name' => 'Size'])->assertOk();
    // This product's deleted "Old size" still holds the name: clean 422, not a DB error.
    $this->patchJson("/api/addon-groups/{$own['uuid']}", ['name' => 'Old size'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
    // Keeping its own name is fine.
    $this->patchJson("/api/addon-groups/{$own['uuid']}", ['name' => 'Size'])->assertOk();
});

it('renames a shared group by the shared rule, deleted groups included', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $this->postJson("/api/products/{$product->uuid}/addon-groups", ['name' => 'Size'])->assertCreated();
    $shared = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Extras']);
    $old = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Sauces']);
    $old->delete();

    // A product's own "Size" does not block renaming a shared group to "Size".
    $this->patchJson("/api/addon-groups/{$shared->uuid}", ['name' => 'Size'])->assertOk();
    // A deleted shared "Sauces" still holds its name: clean 422, not a DB error.
    $this->patchJson("/api/addon-groups/{$shared->uuid}", ['name' => 'Sauces'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
});
