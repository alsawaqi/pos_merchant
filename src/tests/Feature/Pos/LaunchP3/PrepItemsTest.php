<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 P3-4 — prep items, portal side.
 *
 * A prep item (sauce, dough) is a pos_ingredients row with is_prep, a yield
 * and a recipe in pos_ingredient_recipes; components may be prep items (max
 * 3 levels, no cycles, same company). Product, cooked-product and add-on
 * recipes pick prep items like ingredients, and cost goes through them by
 * the explode rule everywhere (product list, editor, Recipe & Cost report).
 */

use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\AddOnGroup;
use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('creates a prep item with a small unit, a yield and a recipe, and shows its cost per base unit and per batch', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $oil = p3Ingredient($ctx['company'], 'Oil', 'ml', '0.004');

    $response = $this->postJson('/api/prep-items', [
        'name' => 'Tomato sauce',
        'name_ar' => 'صلصة طماطم',
        'unit' => 'ml',
        'prep_yield_quantity' => '2000',
        'lines' => [
            ['ingredient_uuid' => $tomato->uuid, 'quantity' => '1.5', 'unit' => 'kg'],
            ['ingredient_uuid' => $oil->uuid, 'quantity' => '100'],
        ],
    ])->assertCreated();

    // Batch: 1500 × 0.002 + 100 × 0.004 = 3.400 → per ml 0.0017.
    expect($response->json('data'))->toMatchArray([
        'name' => 'Tomato sauce',
        'unit' => 'ml',
        'is_prep' => true,
        'prep_yield_quantity' => '2000',
        'unit_cost' => '0.0017',
        'batch_cost' => '3.400',
        'depth' => 1,
    ])->and($response->json('data.lines.0.line_cost'))->toBe('3.000');

    $sauce = Ingredient::query()->where('name', 'Tomato sauce')->firstOrFail();
    expect($sauce->is_prep)->toBeTrue()
        ->and((string) $sauce->default_unit_cost)->toBe('0.000')
        ->and(IngredientRecipe::query()->where('prep_ingredient_id', $sauce->id)->count())->toBe(2);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.prep_item.created', 'auditable_id' => $sauce->id]);
});

it('allows only g, ml or piece, a yield above 0 and at least one line', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $line = [['ingredient_uuid' => $tomato->uuid, 'quantity' => '100']];

    $this->postJson('/api/prep-items', ['name' => 'A', 'unit' => 'kg', 'prep_yield_quantity' => '1', 'lines' => $line])
        ->assertStatus(422)->assertJsonValidationErrors(['unit']);
    $this->postJson('/api/prep-items', ['name' => 'B', 'unit' => 'g', 'prep_yield_quantity' => '0', 'lines' => $line])
        ->assertStatus(422)->assertJsonValidationErrors(['prep_yield_quantity']);
    $this->postJson('/api/prep-items', ['name' => 'C', 'unit' => 'g', 'prep_yield_quantity' => '1', 'lines' => []])
        ->assertStatus(422)->assertJsonValidationErrors(['lines']);
    // Prep items share the ingredients' names.
    $this->postJson('/api/prep-items', ['name' => 'Tomato', 'unit' => 'g', 'prep_yield_quantity' => '1', 'lines' => $line])
        ->assertStatus(422)->assertJsonValidationErrors(['name']);
});

it('nests prep items up to 3 levels and refuses a 4th', function (): void {
    $ctx = makeMerchantActor();
    $flour = p3Ingredient($ctx['company'], 'Flour', 'g', '0.0005');
    $one = p3Prep($ctx['company'], 'Dough', 'g', '1000', [[$flour, '600']]);
    $two = p3Prep($ctx['company'], 'Base', 'piece', '4', [[$one, '1000']]);
    $three = p3Prep($ctx['company'], 'Kit', 'piece', '1', [[$two, '1']]);

    $this->postJson('/api/prep-items', [
        'name' => 'Box', 'unit' => 'piece', 'prep_yield_quantity' => '1',
        'lines' => [['ingredient_uuid' => $three->uuid, 'quantity' => '1']],
    ])->assertStatus(422)->assertJsonPath('message', 'Prep items can be nested at most 3 levels deep: Box → Kit → Base → Dough would be 4.');

    // Deepening the bottom pushes the top past the limit too.
    $deep = p3Prep($ctx['company'], 'Starter', 'g', '100', [[$flour, '100']]);
    $this->patchJson("/api/prep-items/{$one->uuid}", [
        'lines' => [['ingredient_uuid' => $deep->uuid, 'quantity' => '50']],
    ])->assertStatus(422)->assertJsonPath('message', 'Prep items can be nested at most 3 levels deep: Kit → Base → Dough → Starter would be 4.');

    expect($this->getJson("/api/prep-items/{$three->uuid}")->json('data.depth'))->toBe(3);
});

it('refuses a prep recipe that loops back on itself', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $sauce = p3Prep($ctx['company'], 'Sauce', 'ml', '2000', [[$tomato, '1500']]);
    $base = p3Prep($ctx['company'], 'Pizza base', 'piece', '10', [[$sauce, '500']]);

    $this->patchJson("/api/prep-items/{$sauce->uuid}", [
        'lines' => [['ingredient_uuid' => $tomato->uuid, 'quantity' => '1500'], ['ingredient_uuid' => $base->uuid, 'quantity' => '1']],
    ])->assertStatus(422)->assertJsonPath('message', 'A prep item cannot use itself, directly or through another prep item: Sauce → Pizza base → Sauce.');

    $this->patchJson("/api/prep-items/{$sauce->uuid}", [
        'lines' => [['ingredient_uuid' => $sauce->uuid, 'quantity' => '1']],
    ])->assertStatus(422)->assertJsonPath('message', 'A prep item cannot use itself: remove "Sauce" from its own recipe.');

    expect(IngredientRecipe::query()->where('prep_ingredient_id', $sauce->id)->pluck('ingredient_id')->all())->toBe([$tomato->id]);
});

it('refuses a component from another company', function (): void {
    $ctx = makeMerchantActor();
    $other = Ingredient::factory()->create(['name' => 'Foreign tomato']);

    $this->postJson('/api/prep-items', [
        'name' => 'Sauce', 'unit' => 'ml', 'prep_yield_quantity' => '1000',
        'lines' => [['ingredient_uuid' => $other->uuid, 'quantity' => '10']],
    ])->assertStatus(422)->assertJsonPath('message', 'One or more ingredients in the prep recipe do not belong to your company.');
    $this->getJson("/api/prep-items/{$other->uuid}")->assertNotFound();
});

it('lets product, cooked-product and add-on recipes use a prep item, costed through its recipe', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $flour = p3Ingredient($ctx['company'], 'Flour', 'g', '0.0005');
    $sauce = p3Prep($ctx['company'], 'Sauce', 'ml', '2000', [[$tomato, '1500']]); // 0.0015 per ml
    $pizza = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient', 'base_price' => '3.000']);
    $baked = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked', 'base_price' => '1.000']);

    $this->putJson("/api/products/{$pizza->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $sauce->uuid, 'quantity' => '100'],
        ['ingredient_uuid' => $flour->uuid, 'quantity' => '200'],
    ]])->assertOk()
        // 100 ml × 0.0015 + 200 g × 0.0005 = 0.150 + 0.100.
        ->assertJsonPath('data.theoretical_cost', '0.250')
        ->assertJsonPath('data.recipe_lines.0.ingredient.is_prep', true)
        ->assertJsonPath('data.recipe_lines.0.ingredient.default_unit_cost', '0.0015');
    $this->putJson("/api/products/{$baked->uuid}/recipe", ['lines' => [['ingredient_uuid' => $sauce->uuid, 'quantity' => '20']]])
        ->assertOk()->assertJsonPath('data.theoretical_cost', '0.030');

    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Extras']);
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Extra sauce',
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $sauce->uuid, 'direction' => 'add', 'quantity' => '30']],
    ])->assertCreated()->assertJsonPath('data.consumption.0.ingredient.is_prep', true);

    // The product list costs every row through the prep item too.
    $row = collect($this->getJson('/api/products')->assertOk()->json('data'))->firstWhere('uuid', $pizza->uuid);
    expect($row['theoretical_cost'])->toBe('0.250');

    // A raw-ingredient cost change flows through the prep item.
    $tomato->forceFill(['default_unit_cost' => '0.004'])->save();
    expect($this->getJson("/api/products/{$pizza->uuid}")->json('data.theoretical_cost'))->toBe('0.400');
});

it('lists prep items for the recipe pickers but keeps them out of the plain ingredient list', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $sauce = p3Prep($ctx['company'], 'Sauce', 'ml', '2000', [[$tomato, '1500']]);

    $plain = collect($this->getJson('/api/ingredients')->assertOk()->json('data'))->pluck('uuid')->all();
    expect($plain)->toBe([$tomato->uuid]);

    $withPrep = collect($this->getJson('/api/ingredients?include_prep=1')->assertOk()->json('data'))->keyBy('uuid');
    expect($withPrep[$sauce->uuid])->toMatchArray(['is_prep' => true, 'prep_yield_quantity' => '2000', 'default_unit_cost' => '0.0015'])
        ->and($withPrep[$tomato->uuid]['is_prep'])->toBeFalse();

    // A prep item is edited / deleted with its recipe, never as a plain ingredient.
    $this->patchJson("/api/ingredients/{$sauce->uuid}", ['default_unit_cost' => '9'])->assertStatus(422);
    $this->deleteJson("/api/ingredients/{$sauce->uuid}")->assertStatus(422);
    expect($sauce->fresh()->trashed())->toBeFalse();
});

it('refuses to delete a prep item or its ingredients while a recipe uses them, and deletes an unused one', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $sauce = p3Prep($ctx['company'], 'Sauce', 'ml', '2000', [[$tomato, '1500']]);
    $pizza = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);
    ProductRecipe::query()->create(['product_id' => $pizza->id, 'ingredient_id' => $sauce->id, 'quantity' => '100', 'unit_at_set' => 'ml']);

    $this->deleteJson("/api/prep-items/{$sauce->uuid}")->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete prep item "Sauce" — 1 product recipe(s) still use it. Edit those first.');
    // The raw ingredient behind it: no delete, no unit change.
    $this->deleteJson("/api/ingredients/{$tomato->uuid}")->assertStatus(422)
        ->assertJsonPath('message', 'Cannot delete ingredient — 1 prep item recipe(s) still use it. Edit those prep items first.');
    $this->patchJson("/api/ingredients/{$tomato->uuid}", ['unit' => 'kg'])->assertStatus(422);
    // Nor the prep item's own unit while a recipe reads it in ml.
    $this->patchJson("/api/prep-items/{$sauce->uuid}", ['unit' => 'g'])->assertStatus(422);

    ProductRecipe::query()->delete();
    $this->deleteJson("/api/prep-items/{$sauce->uuid}")->assertNoContent();
    expect($sauce->fresh()->trashed())->toBeTrue()
        // Its recipe is kept: an older product recipe version may still name it.
        ->and(IngredientRecipe::query()->where('prep_ingredient_id', $sauce->id)->count())->toBe(1);
    // A deleted prep item no longer blocks its ingredients.
    $this->deleteJson("/api/ingredients/{$tomato->uuid}")->assertNoContent();
});

it('re-publishes every product and add-on group that explodes through a changed prep item', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $sauce = p3Prep($ctx['company'], 'Sauce', 'ml', '2000', [[$tomato, '1500']]);
    $base = p3Prep($ctx['company'], 'Base', 'piece', '10', [[$sauce, '500']]);
    $pizza = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);
    ProductRecipe::query()->create(['product_id' => $pizza->id, 'ingredient_id' => $base->id, 'quantity' => '1', 'unit_at_set' => 'piece']);
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Extras']);
    $addon = AddOn::factory()->for($ctx['company'], 'company')->for($group, 'group')->create(['name' => 'More sauce']);
    AddOnConsumption::query()->create(['add_on_id' => $addon->id, 'ingredient_id' => $sauce->id, 'direction' => 'add', 'quantity' => '30', 'unit' => 'ml']);
    $untouched = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $past = now()->subDay()->startOfSecond();
    DB::table('pos_products')->update(['updated_at' => $past]);
    DB::table('pos_addon_groups')->update(['updated_at' => $past]);

    $this->patchJson("/api/prep-items/{$sauce->uuid}", ['prep_yield_quantity' => '1500'])->assertOk();

    expect($pizza->fresh()->updated_at->gt($past))->toBeTrue()
        ->and($group->fresh()->updated_at->gt($past))->toBeTrue()
        ->and($untouched->fresh()->updated_at->eq($past))->toBeTrue();
});
