<?php

declare(strict_types=1);

/**
 * Phase 7b-3 — Recipe & Cost Report coverage (blueprint §5.11.4).
 */

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only products with a recipe', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Milk', 'default_unit_cost' => '1.000']);

    $latteWithRecipe = Product::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Latte', 'base_price' => '2.500']);
    ProductRecipe::factory()
        ->for($latteWithRecipe, 'product')
        ->for($milk, 'ingredient')
        ->create(['quantity' => '0.200']);

    // Bottle product with NO recipe.
    Product::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Bottled Water', 'base_price' => '0.500']);

    $response = $this->getJson('/api/reports/recipe-cost?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['product_name'])->toBe('Latte');
    // theoretical_cost = 0.200 * 1.000 = 0.200
    expect($rows[0]['theoretical_cost'])->toBe('0.200');
    // profit = 2.500 - 0.200 = 2.300
    expect($rows[0]['profit_per_unit'])->toBe('2.300');
    // margin_pct = 2.300 / 2.500 = 92%
    expect((float) $rows[0]['margin_pct'])->toBe(92.0);
});

it('sums multi-line recipes correctly', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['default_unit_cost' => '1.000']);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create(['default_unit_cost' => '15.000']);

    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'base_price' => '3.000']);
    ProductRecipe::factory()->for($latte, 'product')->for($milk, 'ingredient')->create(['quantity' => '0.200']);
    ProductRecipe::factory()->for($latte, 'product')->for($beans, 'ingredient')->create(['quantity' => '0.018']);

    $response = $this->getJson('/api/reports/recipe-cost?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    // 0.200 * 1.000 + 0.018 * 15.000 = 0.200 + 0.270 = 0.470
    expect($response->json('data.rows.0.theoretical_cost'))->toBe('0.470');
});

it('returns empty rows when company has no products with recipes', function (): void {
    makeMerchantActor();
    // (No products created.)
    $response = $this->getJson('/api/reports/recipe-cost?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.rows'))->toBe([]);
});
