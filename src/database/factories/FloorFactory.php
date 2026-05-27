<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FloorStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Floor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Floor>
 *
 * Does NOT auto-spawn a Branch — caller must pass
 * ->for($branch) so company_id stays consistent (otherwise
 * the Branch factory would create its own Company and trip
 * the (branch_id, name) unique + cross-tenant guards).
 */
class FloorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            // Suffix with random bothify so two factory rows
            // under the same branch don't collide on the
            // unique (branch_id, name) index.
            'name' => fake()->randomElement(['Main Hall', 'Patio', 'VIP', 'Terrace']) . ' ' . strtoupper(Str::random(3)),
            'name_ar' => null,
            'display_order' => 0,
            'status' => FloorStatus::Active->value,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => FloorStatus::Inactive->value,
        ]);
    }
}
