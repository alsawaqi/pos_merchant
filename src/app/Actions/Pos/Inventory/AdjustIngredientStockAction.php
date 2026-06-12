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
 * P-G4 — manual correction to an ingredient's central warehouse pool or a
 * branch balance (physical recount), the ingredient twin of
 * {@see AdjustProductStockAction}. Signed delta + a required reason note.
 * branch null = adjust the central pool. The branch path is equivalent to the
 * existing per-branch {@see AdjustStockAction} (kept for the Inventory page);
 * this one exists so the Stock dialog covers central + branch uniformly.
 */
final readonly class AdjustIngredientStockAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
    ) {}

    public function handle(
        Ingredient $ingredient,
        ?Branch $branch,
        string|float|int $signedQuantity,
        string $note,
        User $actor,
    ): StockMovement {
        $note = trim($note);
        if ($note === '') {
            throw new RuntimeException('Adjustment note is required — explain why the count changed.');
        }
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
