<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\ProductStockMovementType;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 — the canonical entry point for ANY unit-product stock change, the
 * product-units parallel of {@see WriteStockMovementAction}.
 *
 * Every product-stock Action (Receive / Allocate / Transfer / Adjust) delegates
 * here so two invariants hold everywhere:
 *   1. The signed ledger row (pos_product_stock_movements) and the matching
 *      balance move together inside ONE DB transaction — either both or neither.
 *   2. branch == null moves the CENTRAL pool (pos_product_stock.quantity);
 *      a branch moves THAT branch's count (pos_branch_product.stock_qty).
 *
 * Balance rows are created lazily on the first movement. A branch row that was
 * availability-only (stock_qty NULL) becomes unit-tracked (a number) the first
 * time units are allocated to it.
 */
final readonly class WriteProductStockMovementAction
{
    /**
     * @param  string|float|int  $quantity  Signed: positive inflow, negative outflow
     */
    public function handle(
        Product $product,
        ?Branch $branch,
        ProductStockMovementType $type,
        string|float|int $quantity,
        ?User $actor = null,
        ?string $note = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?string $unitCost = null,
    ): ProductStockMovement {
        $companyId = (int) $product->company_id;

        if ($branch !== null && (int) $branch->company_id !== $companyId) {
            throw new RuntimeException('Branch does not belong to this product\'s company.');
        }

        return DB::transaction(function () use (
            $product,
            $branch,
            $type,
            $quantity,
            $actor,
            $note,
            $referenceType,
            $referenceId,
            $reason,
            $unitCost,
            $companyId,
        ): ProductStockMovement {
            $now = now();

            /** @var ProductStockMovement $movement */
            $movement = ProductStockMovement::query()->create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'branch_id' => $branch?->id,
                'movement_type' => $type->value,
                // Wastage only: the reason taxonomy + the per-unit cost frozen at
                // record time (NULL on every other movement type).
                'reason' => $reason,
                'quantity' => (string) $quantity,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'recorded_by_user_id' => $actor?->getKey(),
                'note' => $note,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            if ($branch === null) {
                // Central company pool.
                /** @var ProductStock $central */
                $central = ProductStock::query()->firstOrCreate(
                    ['company_id' => $companyId, 'product_id' => $product->id],
                    ['quantity' => '0.000'],
                );
                $central->increment('quantity', (float) $quantity);
                $central->forceFill(['last_movement_at' => $now])->save();
            } else {
                // Branch unit count. A null stock_qty (availability-only) is
                // promoted to 0 so the increment lands on a number.
                /** @var BranchProduct $bp */
                $bp = BranchProduct::query()->firstOrCreate(
                    ['branch_id' => $branch->id, 'product_id' => $product->id],
                    ['is_available' => true, 'stock_qty' => '0.000'],
                );
                if ($bp->stock_qty === null) {
                    $bp->forceFill(['stock_qty' => '0.000'])->save();
                }
                $bp->increment('stock_qty', (float) $quantity);
            }

            return $movement->fresh();
        });
    }
}
