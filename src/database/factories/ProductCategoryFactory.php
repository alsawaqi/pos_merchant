<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategoryStatus;
use App\Models\Company;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    public function definition(): array
    {
        // Random suffix so multiple categories under the same
        // company don't trip the unique (company_id, name)
        // index when a test builds a batch.
        $base = fake()->randomElement(['Drinks', 'Mains', 'Desserts', 'Sides', 'Starters']);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => $base . ' ' . strtoupper(Str::random(3)),
            'name_ar' => null,
            'description' => null,
            'image_url' => null,
            'display_order' => 0,
            'status' => CategoryStatus::Active->value,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => CategoryStatus::Inactive->value,
        ]);
    }
}
