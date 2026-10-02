<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Support\SingleStockIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LAUNCH-P2 P2-4 — the inventory switches the portal reads to decide which
 * entry points to show.
 *
 *   GET /api/inventory/settings → { single_stock_in: bool }
 *
 * single_stock_in (pos.inventory.single_stock_in, default on): stock comes in
 * through Goods received only, so the branch Restock button, the branch
 * Purchase dialog and the central Receive / Receive & distribute are hidden
 * (and refused by the server).
 */
class InventorySettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::InventoryView->value)) {
            abort(403);
        }

        return response()->json([
            'data' => ['single_stock_in' => SingleStockIn::enabled()],
        ]);
    }
}
