<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 *
 * Default: a +5kg Restock movement at 2.500 OMR/kg. Use
 * named states for adjustment/sale/waste scenarios.
 *
 * IMPORTANT: writing a StockMovement via this factory DOES
 * NOT update the matching BranchStock balance. Tests that
 * need a consistent balance should EITHER use
 * WriteStockMovementAction (which writes both atomically)
 * or build BranchStock + StockMovement rows manually with
 * matching numbers.
 */
class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'ingredient_id' => Ingredient::factory(),
            'movement_type' => StockMovementType::Restock->value,
            'quantity' => '5.000',
            'unit_cost_at_time' => '2.500',
            'reference_type' => null,
            'reference_id' => null,
            'recorded_by_user_id' => null,
            'recorded_by_pos_staff_id' => null,
            'note' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }

    public function initial(): static
    {
        return $this->state(fn (): array => [
            'movement_type' => StockMovementType::Initial->value,
        ]);
    }

    public function adjustment(string $signedQty = '-1.000'): static
    {
        return $this->state(fn (): array => [
            'movement_type' => StockMovementType::Adjustment->value,
            'quantity' => $signedQty,
        ]);
    }

    public function waste(): static
    {
        return $this->state(fn (): array => [
            'movement_type' => StockMovementType::Waste->value,
            'quantity' => '-0.500',
        ]);
    }
}
