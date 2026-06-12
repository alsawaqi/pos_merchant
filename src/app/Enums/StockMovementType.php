<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 5a — the classification of a stock movement row.
 *
 * Per blueprint §5.6.3. Stored as the lowercase string value
 * on pos_stock_movements.movement_type. The application Action
 * layer is the gatekeeper for which types are valid given the
 * caller's context.
 *
 * Phase 5a actions emit: Initial, Restock, Adjustment.
 * Phase 8 (POS sale + add-on) emits: SaleConsumption, AddOnConsumption.
 * Phase 5c (allocations + waste) emits: Waste, Loss, TransferIn, TransferOut.
 *
 * The signed-quantity convention:
 *   POSITIVE: Initial, Restock, Adjustment-up, TransferIn
 *   NEGATIVE: SaleConsumption, AddOnConsumption, Waste, Loss,
 *             Adjustment-down, TransferOut
 *
 * Adjustment is bi-directional — the value just carries the
 * signed delta the merchant specified. Restock is always
 * positive (use Adjustment to correct a wrong-direction
 * restock so the original ledger row stays intact).
 */
enum StockMovementType: string
{
    case Initial = 'initial';
    case Restock = 'restock';
    case SaleConsumption = 'sale_consumption';
    case AddOnConsumption = 'addon_consumption';
    case Waste = 'waste';
    case Loss = 'loss';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    // P-G1 kitchen production: recipe ingredients leave the branch shelf
    // when the chef STARTS a batch (negative), and come back if a manager
    // cancels the in-progress batch (positive). Written by pos_api only;
    // listed here so movement history renders + filters them.
    case ProductionConsumption = 'production_consumption';
    case ProductionReturn = 'production_return';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
