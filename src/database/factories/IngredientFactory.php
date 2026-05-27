<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IngredientUnit;
use App\Models\Company;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ingredient>
 *
 * Default: a kilogram-measured ingredient priced at 2.500
 * OMR/kg with a 1kg threshold — easy to reason about in tests.
 * Random suffix on the name avoids the unique index trip.
 */
class IngredientFactory extends Factory
{
    public function definition(): array
    {
        $base = fake()->randomElement(['Milk', 'Espresso Beans', 'Sugar', 'Cocoa', 'Flour']);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => $base . ' ' . strtoupper(Str::random(3)),
            'name_ar' => null,
            'unit' => IngredientUnit::Kilogram->value,
            'default_unit_cost' => '2.500',
            'min_stock_threshold' => '1.000',
            'primary_supplier_id' => null,
            'status' => 'active',
        ];
    }

    public function noThreshold(): static
    {
        return $this->state(fn (): array => ['min_stock_threshold' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
