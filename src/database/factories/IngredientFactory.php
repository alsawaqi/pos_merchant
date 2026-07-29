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
 * The name suffix is a monotonic per-process counter, NOT randomness:
 * the (company_id, name) unique index must never trip on factory luck —
 * a random 3-letter suffix collided in CI (run 30476056725, "Sugar
 * XGW" twice), and a red CI blocks the production deploy.
 */
class IngredientFactory extends Factory
{
    private static int $seq = 0;

    public function definition(): array
    {
        $base = fake()->randomElement(['Milk', 'Espresso Beans', 'Sugar', 'Cocoa', 'Flour']);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => $base.' '.strtoupper(Str::random(3)).'-'.++self::$seq,
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
