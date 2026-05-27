<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRecipe>
 *
 * Caller MUST provide ->for($product) and ->for($ingredient)
 * so the composite-unique (product_id, ingredient_id) holds
 * and so both belong to the same tenant. Tests that skip
 * spawn random unrelated tenants — rarely what you want.
 */
class ProductRecipeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity' => '1.000',
            'unit_at_set' => 'kg',
            'sort_order' => 0,
        ];
    }
}
