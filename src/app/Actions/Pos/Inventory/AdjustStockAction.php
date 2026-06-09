<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\User;
use RuntimeException;

/**
 * Phase 5a — manual stock adjustment.
 *
 * Used to correct discrepancies the merchant finds during a
 * physical count. The merchant supplies a SIGNED delta + a
 * required reason note. Positive = found more than expected,
 * negative = found less.
 *
 * Note is mandatory because adjustments are the most audit-
 * sensitive movement type — without a reason, the ledger
 * shows mystery losses that hurt theft investigations.
 *
 * unit_cost defaults to the ingredient's default_unit_cost
 * (no override needed for adjustments — they're corrections
 * to the existing inventory, not new purchases).
 *
 * Delegates atomicity to WriteStockMovementAction.
 */
final readonly class AdjustStockAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
        private IngredientUnitConverter $units,
    ) {}

    /**
     * @param  string|float|int  $signedQuantity  Required; signed delta in [$unit]
     * @param  string            $note            Required; reason for the adjustment
     * @param  string|null       $unit            Entered unit (alt-unit name, or null
     *                                            = base); the signed delta is converted
     *                                            to base before it touches stock (#13).
     */
    public function handle(
        Branch $branch,
        Ingredient $ingredient,
        string|float|int $signedQuantity,
        string $note,
        User $actor,
        ?string $unit = null,
    ): StockMovement {
        $note = trim($note);
        if ($note === '') {
            throw new RuntimeException('Adjustment note is required — explain why the stock count changed.');
        }

        // #13 — convert the signed delta to base units (sign preserved; factor > 0).
        $signedQuantity = $this->units->toBase($ingredient, $signedQuantity, $unit);

        if ((float) $signedQuantity === 0.0) {
            throw new RuntimeException('Adjustment quantity cannot be zero.');
        }

        return $this->writeMovement->handle(
            branch: $branch,
            ingredient: $ingredient,
            type: StockMovementType::Adjustment,
            quantity: $signedQuantity,
            unitCostAtTime: $ingredient->default_unit_cost ?? 0,
            actor: $actor,
            note: $note,
        );
    }
}
