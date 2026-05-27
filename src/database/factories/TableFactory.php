<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use App\Models\Company;
use App\Models\Floor;
use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Table>
 *
 * Caller MUST provide ->for($floor) so the floor's
 * company_id matches the table's company_id (the action
 * layer enforces tenancy via floor_id lookup, but the test
 * setup must give consistent IDs).
 */
class TableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'floor_id' => Floor::factory(),
            // bothify with a 3-digit number so two factory
            // rows in the same floor don't trip the unique
            // (floor_id, label) index.
            'label' => 'T' . fake()->unique()->numerify('###'),
            'seats' => 4,
            'min_party' => null,
            'max_party' => null,
            'shape' => TableShape::Square->value,
            'notes' => null,
            'qr_token' => Str::random(24),
            'status' => TableStatus::Active->value,
            'display_order' => 0,
            // Default to NULL positions — most tests don't
            // care about the visual layout, and the planner
            // happily auto-arranges NULL-position tables.
            // Tests that DO care use ->state(['position_x' => ...]).
            'position_x' => null,
            'position_y' => null,
            'width' => null,
            'height' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => TableStatus::Inactive->value,
        ]);
    }

    public function counter(): static
    {
        return $this->state(fn (): array => [
            'shape' => TableShape::Counter->value,
            'seats' => 1,
        ]);
    }
}
