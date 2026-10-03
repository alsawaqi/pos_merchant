<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 fix order 1, L8 — ONE rule for every recipe write:
 * "Edit recipes" + catalogue.view.
 *
 * Before, the product recipe PUT and prep writes needed only "Edit recipes",
 * add-on stock usage needed catalogue.manage AND "Edit recipes", and the
 * wizard needed catalogue.manage — so a chef role [catalogue.view, Edit
 * recipes] could save a recipe through one door and not another, and recipe
 * history was hidden from catalogue viewers. Now:
 *  - a chef [catalogue.view, Edit recipes] edits product recipes, prep items
 *    and option stock usage, and nothing else of the catalogue;
 *  - "Edit recipes" without catalogue.view grants nothing;
 *  - a Viewer reads the product and its recipe history.
 */

use App\Enums\MerchantRole;
use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** A merchant user holding a custom role with exactly these permissions. */
function l8ActorWith(array $permissions): array
{
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $role = Role::query()->create(['name' => 'Custom '.uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['company']->id]);
    $role->syncPermissions($permissions);
    $ctx['user']->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $ctx;
}

/** A latte, an option group with one option, and an ingredient. */
function l8Fixture(array $ctx): array
{
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0005');
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'stock_mode' => 'ingredient']);
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    $option = AddOn::query()->create(['company_id' => $ctx['company']->id, 'add_on_group_id' => $group->id, 'name' => 'Extra milk', 'price_delta' => '0.200', 'status' => 'active']);

    return ['milk' => $milk, 'latte' => $latte, 'option' => $option];
}

it('lets a chef role [catalogue.view, Edit recipes] write every kind of recipe, and nothing else of the catalogue', function (): void {
    $ctx = l8ActorWith(['catalogue.view', 'catalogue.recipes.manage']);
    $f = l8Fixture($ctx);
    $line = [['type' => 'ingredient', 'ingredient_uuid' => $f['milk']->uuid, 'direction' => 'add', 'quantity' => '60']];

    // Product recipe.
    $this->putJson("/api/products/{$f['latte']->uuid}/recipe", ['lines' => [['ingredient_uuid' => $f['milk']->uuid, 'quantity' => '180']]])->assertOk();
    // Prep item.
    $this->postJson('/api/prep-items', [
        'name' => 'Foam', 'unit' => 'ml', 'prep_yield_quantity' => '500',
        'lines' => [['ingredient_uuid' => $f['milk']->uuid, 'quantity' => '400']],
    ])->assertCreated();
    // Option stock usage — the modal re-sends the option's unchanged fields too.
    $this->patchJson("/api/addons/{$f['option']->uuid}", [
        'name' => 'Extra milk', 'price_delta' => '0.200', 'consumption' => $line,
    ])->assertOk();
    expect(AddOnConsumption::query()->where('add_on_id', $f['option']->id)->count())->toBe(1);

    // The rest of the catalogue is not theirs.
    $this->patchJson("/api/addons/{$f['option']->uuid}", ['price_delta' => '0.100'])->assertForbidden();
    $this->patchJson("/api/products/{$f['latte']->uuid}", ['name' => 'Flat white'])->assertForbidden();
    expect((string) $f['option']->fresh()->price_delta)->toBe('0.200')
        ->and($f['latte']->fresh()->name)->toBe('Latte');
});

it('grants nothing with "Edit recipes" alone (no catalogue.view)', function (): void {
    $ctx = l8ActorWith(['catalogue.recipes.manage', 'inventory.view']);
    $f = l8Fixture($ctx);

    $this->putJson("/api/products/{$f['latte']->uuid}/recipe", ['lines' => [['ingredient_uuid' => $f['milk']->uuid, 'quantity' => '180']]])->assertForbidden();
    $this->postJson('/api/prep-items', [
        'name' => 'Foam', 'unit' => 'ml', 'prep_yield_quantity' => '500',
        'lines' => [['ingredient_uuid' => $f['milk']->uuid, 'quantity' => '400']],
    ])->assertForbidden();
    $this->patchJson("/api/addons/{$f['option']->uuid}", [
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $f['milk']->uuid, 'direction' => 'add', 'quantity' => '60']],
    ])->assertForbidden();

    expect(ProductRecipe::query()->count())->toBe(0)
        ->and(AddOnConsumption::query()->count())->toBe(0);
});

it('shows a Viewer the product and its recipe history', function (): void {
    $manager = makeMerchantActor(MerchantRole::Manager->value);
    $f = l8Fixture($manager);
    $this->putJson("/api/products/{$f['latte']->uuid}/recipe", ['lines' => [['ingredient_uuid' => $f['milk']->uuid, 'quantity' => '180']], 'note' => 'Launch recipe'])->assertOk();

    // A Viewer of the same company.
    $viewer = User::factory()->create(['company_id' => $manager['company']->id, 'user_type' => 'merchant', 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($manager['company']->id);
    $viewer->assignRole(MerchantRole::Viewer->value);
    $this->actingAs($viewer);

    $this->getJson("/api/products/{$f['latte']->uuid}")->assertOk()->assertJsonPath('data.recipe_lines.0.quantity', '180.000');
    $history = $this->getJson("/api/products/{$f['latte']->uuid}/recipe-history")->assertOk();
    expect($history->json('data.versions.0.note'))->toBe('Launch recipe')
        ->and($history->json('data.versions.0.changes.0.after'))->toBe('180 ml');
    // ... but cannot change it.
    $this->putJson("/api/products/{$f['latte']->uuid}/recipe", ['lines' => []])->assertForbidden();
});

it('tells the Roles page that "Edit recipes" works together with seeing the catalogue', function (): void {
    $entry = collect(PermissionCatalog::merchant())->firstWhere('key', 'catalogue');
    $recipes = collect($entry['permissions'])->firstWhere('key', 'catalogue.recipes.manage');

    expect($recipes['label_en'])->toContain('needs "See categories + products + add-ons"')
        ->and($recipes['label_ar'])->toContain('عرض الفئات والمنتجات والإضافات');
});
