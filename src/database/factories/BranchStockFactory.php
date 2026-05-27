<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchStock>
 *
 * Caller MUST provide ->for($branch) and ->for($ingredient)
 * so the composite-unique index isn't violated and so the
 * branch + ingredient belong to the same tenant. Tests that
 * skip this get an autospawned chain with random unrelated
 * tenants, which is rarely what you want.
 */
class BranchStockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity' => '10.000',
            'last_movement_at' => now()->subHour(),
        ];
    }

    public function empty(): static
    {
        return $this->state(fn (): array => ['quantity' => '0.000']);
    }

    public function low(): static
    {
        return $this->state(fn (): array => ['quantity' => '0.500']);
    }
}
