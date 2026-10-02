<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 P3-1 — the recipe editor keeps the entered unit.
 *
 * "5 g" of a kg ingredient used to come back as "0.005 kg" (an invitation to
 * a 1000× "correction"), the unit picker had no piece unit, and anything
 * under 0.05 g of a kg ingredient was silently stored as 0. Now a line keeps
 * entered_unit / entered_quantity next to the BASE quantity the device API
 * reads; the piece unit is offered; an amount that rounds to 0 is refused.
 * Same for add-on stock-usage lines and prep recipes.
 */

use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\AddOnGroup;
use App\Models\IngredientAltUnit;
use App\Models\IngredientRecipe;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('stores the base quantity and reopens the line exactly as typed ("5 g" of a kg ingredient)', function (): void {
    $ctx = makeMerchantActor();
    $saffron = p3Ingredient($ctx['company'], 'Saffron', 'kg', '1200.000');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $saffron->uuid, 'quantity' => '5', 'unit' => 'g']],
    ])->assertOk();

    $line = ProductRecipe::query()->where('product_id', $product->id)->firstOrFail();
    // The device keeps reading the BASE quantity and unit.
    expect((string) $line->quantity)->toBe('0.005')
        ->and($line->unit_at_set->value)->toBe('kg')
        ->and($line->entered_unit)->toBe('g')
        ->and((string) $line->entered_quantity)->toBe('5');

    $show = $this->getJson("/api/products/{$product->uuid}")->assertOk();
    expect($show->json('data.recipe_lines.0.entered_unit'))->toBe('g')
        ->and($show->json('data.recipe_lines.0.entered_quantity'))->toBe('5')
        ->and($show->json('data.recipe_lines.0.quantity'))->toBe('0.005');
});

it('offers the piece unit: "2 loaves" is stored as 1000 g and reopens as 2 pieces', function (): void {
    $ctx = makeMerchantActor();
    $bread = p3Ingredient($ctx['company'], 'Bread', 'g', '0.001', ['piece_unit_label' => 'loaf', 'units_per_piece' => '500']);
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $bread->uuid, 'quantity' => '2', 'unit' => '@piece']],
    ])->assertOk();

    $line = ProductRecipe::query()->where('product_id', $product->id)->firstOrFail();
    expect((string) $line->quantity)->toBe('1000.000')
        ->and($line->entered_unit)->toBe('@piece')
        ->and((string) $line->entered_quantity)->toBe('2');

    $show = $this->getJson("/api/products/{$product->uuid}")->assertOk();
    expect($show->json('data.recipe_lines.0.entered_unit'))->toBe('@piece')
        ->and($show->json('data.recipe_lines.0.entered_quantity'))->toBe('2')
        ->and($show->json('data.recipe_lines.0.ingredient.piece_unit_label'))->toBe('loaf');
});

it('refuses an amount that would round to 0 in the base unit instead of saving 0', function (): void {
    $ctx = makeMerchantActor();
    $saffron = p3Ingredient($ctx['company'], 'Saffron', 'kg', '1200.000');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $response = $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $saffron->uuid, 'quantity' => '0.04', 'unit' => 'g']],
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('Saffron: 0.04 g is too small to record')
        ->toContain('Enter at least 0.05 g');
    expect(ProductRecipe::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and(ProductRecipeVersion::query()->where('product_id', $product->id)->count())->toBe(0);

    // 0.05 g is the smallest amount that records (0.0001 kg).
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $saffron->uuid, 'quantity' => '0.05', 'unit' => 'g']],
    ])->assertOk();
    expect((string) ProductRecipe::query()->where('product_id', $product->id)->value('quantity'))->toBe('0.0001');
});

it('refuses more than 4 decimal places so the line can reopen as typed', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0005');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '1.23456']],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity']);
});

it('reopens in the base unit once the entered unit no longer converts to what is stored', function (): void {
    $ctx = makeMerchantActor();
    $cups = p3Ingredient($ctx['company'], 'Cups', 'piece', '0.010');
    $box = IngredientAltUnit::query()->create(['company_id' => $ctx['company']->id, 'ingredient_id' => $cups->id, 'name' => 'box', 'factor' => '12']);
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '2', 'unit' => 'box']],
    ])->assertOk();
    expect($this->getJson("/api/products/{$product->uuid}")->json('data.recipe_lines.0.entered_unit'))->toBe('box');

    // The merchant re-sizes the box: "2 box" would now mean 20, but 24 is stored.
    $box->forceFill(['factor' => '10'])->save();
    $line = $this->getJson("/api/products/{$product->uuid}")->json('data.recipe_lines.0');
    expect($line['entered_unit'])->toBeNull()
        ->and($line['entered_quantity'])->toBeNull()
        ->and($line['quantity'])->toBe('24.000');
});

it('treats a pre-P3 line re-saved in its base unit as unchanged (no version, no audit)', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'l', '0.400');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);
    // Written before P3: no entered columns.
    ProductRecipe::query()->create(['product_id' => $product->id, 'ingredient_id' => $milk->id, 'quantity' => '0.180', 'unit_at_set' => 'l']);

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '0.18']],
    ])->assertOk();

    expect(ProductRecipeVersion::query()->where('product_id', $product->id)->count())->toBe(0);
    $this->assertDatabaseMissing('pos_audit_logs', ['event' => 'catalogue.product.recipe_updated', 'auditable_id' => $product->id]);

    // Re-typing the same amount in ml IS a change of what the editor shows.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '180', 'unit' => 'ml']],
    ])->assertOk();
    $line = ProductRecipe::query()->where('product_id', $product->id)->firstOrFail();
    expect((string) $line->quantity)->toBe('0.180')
        ->and($line->entered_unit)->toBe('ml')
        ->and(ProductRecipeVersion::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('keeps the entered unit on add-on stock-usage lines and refuses an amount that rounds to 0', function (): void {
    $ctx = makeMerchantActor();
    $beans = p3Ingredient($ctx['company'], 'Beans', 'kg', '8.000');
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Shots']);

    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Extra shot',
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $beans->uuid, 'direction' => 'add', 'quantity' => '9', 'unit' => 'g']],
    ])->assertCreated()
        ->assertJsonPath('data.consumption.0.entered_unit', 'g')
        ->assertJsonPath('data.consumption.0.entered_quantity', '9')
        ->assertJsonPath('data.consumption.0.quantity', '0.009');

    $line = AddOnConsumption::query()->firstOrFail();
    expect((string) $line->quantity)->toBe('0.009')
        ->and($line->unit)->toBe('kg')
        ->and($line->entered_unit)->toBe('g');

    $addon = AddOn::query()->where('name', 'Extra shot')->firstOrFail();
    $response = $this->patchJson("/api/addons/{$addon->uuid}", [
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $beans->uuid, 'direction' => 'add', 'quantity' => '0.01', 'unit' => 'g']],
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('too small to record');
    expect((string) AddOnConsumption::query()->value('quantity'))->toBe('0.009');
});

it('keeps the entered unit on prep recipe lines', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');

    $uuid = $this->postJson('/api/prep-items', [
        'name' => 'Tomato sauce',
        'unit' => 'ml',
        'prep_yield_quantity' => '2000',
        'lines' => [['ingredient_uuid' => $tomato->uuid, 'quantity' => '1.5', 'unit' => 'kg']],
    ])->assertCreated()
        ->assertJsonPath('data.lines.0.entered_unit', 'kg')
        ->assertJsonPath('data.lines.0.entered_quantity', '1.5')
        ->assertJsonPath('data.lines.0.quantity', '1500.000')
        ->json('data.uuid');

    $line = IngredientRecipe::query()->firstOrFail();
    expect((string) $line->quantity)->toBe('1500.000')
        ->and($line->entered_unit)->toBe('kg');
    $this->getJson("/api/prep-items/{$uuid}")->assertOk()->assertJsonPath('data.lines.0.entered_unit', 'kg');
});
