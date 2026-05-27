<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IngredientUnit;
use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\WasteRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WasteRecord>
 *
 * Default: 1.000 kg of an ingredient wasted because it spoiled,
 * at 2.500 OMR/kg unit cost. Caller should pass ->for($branch)
 * and ->for($ingredient) for tenant consistency.
 *
 * IMPORTANT: a WasteRecord built via this factory does NOT
 * write the matching stock_movement or update branch_stock.
 * Tests that need the full waste-event invariants should call
 * RecordWasteAction (Phase 5c-4) instead.
 */
class WasteRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'branch_id' => Branch::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity' => '1.000',
            'reason' => WasteReason::Spoiled->value,
            'unit_at_set' => IngredientUnit::Kilogram->value,
            'unit_cost_at_time' => '2.500',
            'notes' => null,
            'recorded_by_user_id' => null,
            'occurred_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'reason' => WasteReason::Expired->value,
        ]);
    }

    public function other(string $notes): static
    {
        return $this->state(fn (): array => [
            'reason' => WasteReason::Other->value,
            'notes' => $notes,
        ]);
    }
}
