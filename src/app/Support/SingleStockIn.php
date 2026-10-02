<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * LAUNCH-P2 P2-4 — one way to bring stock in for the pilot: Goods received.
 *
 * While pos.inventory.single_stock_in is on (the default), the three other
 * stock-in entry points — the branch Restock button, the branch Purchase
 * dialog and the central warehouse Receive (incl. Receive & distribute) —
 * are refused with a clear 422. The portal hides them from the same flag.
 */
final class SingleStockIn
{
    public const CODE = 'single_stock_in';

    public static function enabled(): bool
    {
        return (bool) config('pos.inventory.single_stock_in', true);
    }

    /** The refusal to return, or NULL when the entry point is open. */
    public static function refusal(): ?JsonResponse
    {
        if (! self::enabled()) {
            return null;
        }

        return response()->json([
            'message' => 'Stock comes in through Goods received only. Record this delivery as a goods-received note (Inventory → Purchase receipts).',
            'code' => self::CODE,
        ], 422);
    }
}
