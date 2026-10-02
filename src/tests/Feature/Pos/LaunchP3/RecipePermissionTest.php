<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 P3-3 — the "Edit recipes" permission (catalogue.recipes.manage).
 *
 * Required for product recipes, prep-item recipes and yield, and add-on
 * stock-usage lines; catalogue.manage alone no longer allows those edits but
 * still allows the rest of the catalogue. Defaults: Super Admin + Manager.
 * Custom roles do not get it automatically. The server refuses with 403.
 */

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** A merchant user holding a custom role with exactly these permissions. */
function p3ActorWith(array $permissions): array
{
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $role = Role::query()->create(['name' => 'Custom '.uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['company']->id]);
    $role->syncPermissions($permissions);
    $ctx['user']->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $ctx;
}

it('adds "Edit recipes" to the catalogue with English and Arabic labels on the Roles page', function (): void {
    expect(MerchantPermission::CatalogueRecipesManage->value)->toBe('catalogue.recipes.manage');

    makeMerchantActor();
    $catalogue = collect($this->getJson('/api/roles/catalog')->assertOk()->json('data'))->firstWhere('key', 'catalogue');
    $entry = collect($catalogue['permissions'])->firstWhere('key', 'catalogue.recipes.manage');

    expect($entry['label_en'])->toStartWith('Edit recipes')
        ->and($entry['label_ar'])->toStartWith('تعديل الوصفات')
        ->and(PermissionCatalog::allMerchantKeys())->toContain('catalogue.recipes.manage');
});

it('gives it to the Super Admin and Manager system roles only', function (): void {
    $ctx = makeMerchantActor();
    app(SeedMerchantRolesAction::class)->handle($ctx['company']->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);

    $holders = collect(MerchantRole::values())
        ->filter(fn (string $role): bool => Role::findByName($role, 'web')->hasPermissionTo('catalogue.recipes.manage'))
        ->values()
        ->all();

    expect($holders)->toBe([MerchantRole::SuperAdmin->value, MerchantRole::Manager->value]);
});

it('refuses a product recipe change with catalogue.manage alone, and allows it with "Edit recipes"', function (): void {
    $ctx = p3ActorWith(['catalogue.view', 'catalogue.manage', 'inventory.view']);
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0005');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);
    $payload = ['lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '180']]];

    $this->putJson("/api/products/{$product->uuid}/recipe", $payload)->assertForbidden();
    expect(ProductRecipe::query()->count())->toBe(0);

    // catalogue.manage still edits the rest of the product.
    $this->patchJson("/api/products/{$product->uuid}", ['name' => 'Latte'])->assertOk();

    $chef = p3ActorWith(['catalogue.view', 'catalogue.recipes.manage']);
    $chefProduct = Product::factory()->for($chef['company'], 'company')->create(['stock_mode' => 'ingredient']);
    $chefMilk = p3Ingredient($chef['company'], 'Milk', 'ml', '0.0005');
    $this->putJson("/api/products/{$chefProduct->uuid}/recipe", ['lines' => [['ingredient_uuid' => $chefMilk->uuid, 'quantity' => '180']]])
        ->assertOk();
});

it('creates a product with the wizard without a recipe, but refuses one that carries recipe lines', function (): void {
    $ctx = p3ActorWith(['catalogue.view', 'catalogue.manage', 'inventory.view']);
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0005');
    $base = [
        'product' => ['name' => 'Latte', 'base_price' => '1.500', 'stock_mode' => 'ingredient'],
        'addon_group_uuids' => [], 'owned_groups' => [], 'component_lines' => [], 'delivery_prices' => [],
    ];

    $this->postJson('/api/products/wizard', $base + ['recipe_lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '180']]])
        ->assertForbidden();
    $this->postJson('/api/products/wizard', array_replace($base, [
        'recipe_lines' => [],
        'owned_groups' => [['name' => 'Size', 'options' => [[
            'name' => 'Large',
            'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $milk->uuid, 'quantity' => '60']],
        ]]]],
    ]))->assertForbidden();
    expect(Product::query()->count())->toBe(0);

    $this->postJson('/api/products/wizard', $base + ['recipe_lines' => []])->assertCreated();
});

it('refuses changing an option\'s stock usage without "Edit recipes", rolling the whole save back', function (): void {
    $ctx = p3ActorWith(['catalogue.view', 'catalogue.manage', 'inventory.view']);
    $beans = p3Ingredient($ctx['company'], 'Beans', 'g', '0.008');
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Shots']);
    $line = [['type' => 'ingredient', 'ingredient_uuid' => $beans->uuid, 'direction' => 'add', 'quantity' => '9']];

    // A new option WITH lines: refused; WITHOUT: fine.
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", ['name' => 'Extra shot', 'consumption' => $line])->assertForbidden();
    expect(AddOn::query()->count())->toBe(0);
    $uuid = $this->postJson("/api/addon-groups/{$group->uuid}/addons", ['name' => 'Extra shot'])->assertCreated()->json('data.uuid');

    // Adding lines to it, with a rename in the same save: refused, nothing changes.
    $this->patchJson("/api/addons/{$uuid}", ['name' => 'Double shot', 'consumption' => $line])->assertForbidden();
    expect(AddOn::query()->value('name'))->toBe('Extra shot')
        ->and(AddOnConsumption::query()->count())->toBe(0);

    // The modal re-sends the unchanged (empty) set with a rename: allowed.
    $this->patchJson("/api/addons/{$uuid}", ['name' => 'Double shot', 'consumption' => []])->assertOk();
    expect(AddOn::query()->value('name'))->toBe('Double shot');
});

it('lets a role holding "Edit recipes" change option stock usage', function (): void {
    $ctx = p3ActorWith(['catalogue.view', 'catalogue.manage', 'catalogue.recipes.manage', 'inventory.view']);
    $beans = p3Ingredient($ctx['company'], 'Beans', 'g', '0.008');
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Shots']);

    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Extra shot',
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $beans->uuid, 'direction' => 'add', 'quantity' => '9']],
    ])->assertCreated();
    expect(AddOnConsumption::query()->count())->toBe(1);
});

it('requires "Edit recipes" to create, change or delete a prep item, and lets catalogue or inventory viewers read them', function (): void {
    $ctx = p3ActorWith(['catalogue.view', 'catalogue.manage', 'inventory.view', 'inventory.manage']);
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $sauce = p3Prep($ctx['company'], 'Sauce', 'ml', '2000', [[$tomato, '1500']]);
    $payload = ['name' => 'Pesto', 'unit' => 'g', 'prep_yield_quantity' => '500', 'lines' => [['ingredient_uuid' => $tomato->uuid, 'quantity' => '100']]];

    $this->postJson('/api/prep-items', $payload)->assertForbidden();
    $this->patchJson("/api/prep-items/{$sauce->uuid}", ['prep_yield_quantity' => '1000'])->assertForbidden();
    $this->deleteJson("/api/prep-items/{$sauce->uuid}")->assertForbidden();
    expect((string) $sauce->fresh()->prep_yield_quantity)->toBe('2000');

    $this->getJson('/api/prep-items')->assertOk()->assertJsonPath('data.0.name', 'Sauce');
    $this->getJson("/api/prep-items/{$sauce->uuid}/history")->assertOk();

    $nobody = p3ActorWith(['customers.view']);
    $this->getJson('/api/prep-items')->assertForbidden();
});
