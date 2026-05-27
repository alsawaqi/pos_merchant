<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductRecipeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRecipeVersion>
 */
class ProductRecipeVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            // Default empty snapshot — tests that need a
            // meaningful body should override.
            'recipe_json' => [],
            'edited_by_user_id' => null,
            'note' => null,
            'edited_at' => now(),
        ];
    }
}
