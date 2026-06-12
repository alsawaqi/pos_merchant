<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 7 — classification of a UNIT (finished-good) PRODUCT stock movement,
 * stored on pos_product_stock_movements.movement_type. The product-units
 * parallel of {@see StockMovementType} (which is ingredient-only).
 *
 * Signed-quantity convention:
 *   POSITIVE: Received (+central), AllocationIn (+branch), TransferIn (+branch),
 *             Adjustment-up
 *   NEGATIVE: AllocationOut (-central), TransferOut (-branch), SaleConsumption
 *             (-branch), Waste, Adjustment-down
 */
enum ProductStockMovementType: string
{
    case Received = 'received';
    case AllocationOut = 'allocation_out';
    case AllocationIn = 'allocation_in';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case SaleConsumption = 'sale_consumption';
    case Adjustment = 'adjustment';
    case Waste = 'waste';
    // P-G1 kitchen production: a finished batch lands its pieces in the
    // branch shelf stock (+branch). Written by pos_api only.
    case Produced = 'produced';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
