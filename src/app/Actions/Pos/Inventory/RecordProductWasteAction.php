<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\ProductStockMovementType;
use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use App\Support\MerchantTenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Record wastage of a PRODUCT at a branch — the product-units parallel of
 * {@see RecordWasteAction} (which wastes an ingredient). Works for COOKED
 * products (kitchen shelf) and READY/BOUGHT-IN products (purchased unit stock);
 * both hold their branch stock on pos_branch_product.stock_qty.
 *
 * One signed-negative ProductStockMovement of type 'waste' is written via the
 * canonical {@see WriteProductStockMovementAction} (so the ledger ⇄ shelf
 * invariant holds), carrying the WasteReason and a per-unit cost FROZEN at this
 * moment so a later price/recipe edit doesn't shift the recorded loss. The cost
 * is the product's cost_price when set, else (for a cooked item with no
 * cost_price) its recipe cost. The Loss/Waste report surfaces it automatically.
 *
 * Wastage is a LOSS-tracking movement, NOT an expense: the cash model already
 * expensed the cost at purchase (unit) or production (cooked), so booking an
 * expense here would double-count.
 *
 * Guards: quantity > 0; reason in WasteReason ('other' requires notes); the
 * product is cooked or unit (made-to-order/untracked have no branch shelf);
 * branch + product in the actor's tenant; and the branch shelf must hold enough
 * to absorb the waste (it can never be driven negative — record a positive
 * adjustment first if the accounting balance is wrong).
 *
 * Audit event: inventory.product_waste.recorded.
 */
final readonly class RecordProductWasteAction
{
    public function __construct(
        private WriteProductStockMovementAction $writeMovement,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  string|float|int  $quantity  ABSOLUTE positive number of units wasted.
     */
    public function handle(
        Branch $branch,
        Product $product,
        string|float|int $quantity,
        WasteReason $reason,
        User $actor,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
    ): ProductStockMovement {
        $companyId = $this->tenant->requiredId();

        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }
        if ((int) $product->company_id !== $companyId) {
            throw new RuntimeException('Product does not belong to your company.');
        }
        // Only shelf-tracked products can be wasted. Made-to-order ('ingredient')
        // products hold no branch shelf (their ingredients are wasted instead);
        // untracked products hold no stock at all.
        if (! in_array($product->stock_mode, ['unit', 'cooked'], true)) {
            throw new RuntimeException('Only cooked or ready/bought-in products hold branch stock that can be wasted.');
        }

        $absQty = (float) $quantity;
        if ($absQty <= 0) {
            throw new RuntimeException('Waste quantity must be positive.');
        }
        if ($reason === WasteReason::Other && trim((string) $notes) === '') {
            throw new RuntimeException("Notes are required when reason is 'other'.");
        }

        // Freeze the honest per-unit cost: cost_price when set, else the cooked
        // recipe cost (theoreticalCost() is 0 for a unit product with no recipe).
        $costPrice = (float) $product->cost_price;
        $unitCost = $costPrice > 0
            ? number_format($costPrice, 3, '.', '')
            : $product->theoreticalCost();

        $occurredAt = $occurredAt instanceof DateTimeInterface
            ? Carbon::instance($occurredAt)
            : now();

        return DB::transaction(function () use (
            $branch,
            $product,
            $absQty,
            $reason,
            $unitCost,
            $actor,
            $notes,
            $occurredAt,
            $companyId,
        ): ProductStockMovement {
            // Lock the branch shelf row INSIDE the transaction so the
            // sufficient-stock check and the decrement are serialised against a
            // concurrent waste / sale — waste can never drive the shelf negative.
            $currentBalance = (float) (DB::table('pos_branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->value('stock_qty') ?? 0.0);
            if ($currentBalance < $absQty) {
                throw new RuntimeException(sprintf(
                    'Not enough stock to waste — branch holds %s but waste is %s.',
                    number_format($currentBalance, 3, '.', ''),
                    number_format($absQty, 3, '.', ''),
                ));
            }

            $movement = $this->writeMovement->handle(
                product: $product,
                branch: $branch,
                type: ProductStockMovementType::Waste,
                // SIGNED — negative for the ledger / shelf decrement.
                quantity: '-' . number_format($absQty, 3, '.', ''),
                actor: $actor,
                note: $notes,
                reason: $reason->value,
                unitCost: $unitCost,
            );
            $movement->forceFill(['occurred_at' => $occurredAt])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.product_waste.recorded',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: ProductStockMovement::class,
                auditableId: $movement->id,
                newValues: [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => number_format($absQty, 3, '.', ''),
                    'reason' => $reason->value,
                    'unit_cost' => $unitCost,
                    'total_cost' => number_format($absQty * (float) $unitCost, 3, '.', ''),
                    'notes' => $notes,
                ],
            ));

            return $movement->fresh(['product', 'branch']);
        });
    }
}
