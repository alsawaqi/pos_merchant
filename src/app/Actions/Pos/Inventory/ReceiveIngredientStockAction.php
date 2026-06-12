<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\User;
use RuntimeException;

/**
 * P-G4 — receive an ingredient purchase into the company's CENTRAL warehouse
 * ("100 kg of sugar arrived"), the ingredient twin of
 * {@see ReceiveProductStockAction}. Positive inflow credited to
 * pos_ingredient_stock.quantity, before the merchant allocates stock out to
 * branches. Quantity is in the ingredient's BASE unit. The ledger row
 * snapshots the ingredient's default unit cost so COGS reads stay consistent.
 */
final readonly class ReceiveIngredientStockAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
    ) {}

    public function handle(
        Ingredient $ingredient,
        string|float|int $quantity,
        ?string $note,
        User $actor,
    ): StockMovement {
        if ((float) $quantity <= 0) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }

        return $this->writeMovement->handle(
            branch: null,
            ingredient: $ingredient,
            type: StockMovementType::Received,
            quantity: $quantity,
            unitCostAtTime: $ingredient->default_unit_cost ?? 0,
            actor: $actor,
            note: $note,
        );
    }
}
