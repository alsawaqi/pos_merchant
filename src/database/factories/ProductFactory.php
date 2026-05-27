<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 *
 * Auto-spawns a Company only when the test forgets to pass
 * one — caller usually does ->for($company) so the
 * company_id stays consistent with any category they also
 * supply.
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'category_id' => null,
            'sku' => null,
            'barcode' => null,
            'name' => fake()->words(2, true),
            'name_ar' => null,
            'description' => null,
            'image_url' => null,
            // 0.500 → 49.999 OMR — typical menu item range.
            'base_price' => fake()->randomFloat(3, 0.5, 49.999),
            'cost_price' => null,
            'tax_rate' => null,
            'display_order' => 0,
            'status' => ProductStatus::Active->value,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Inactive->value,
        ]);
    }

    public function withSku(string $sku): static
    {
        return $this->state(fn (): array => ['sku' => $sku]);
    }
}
