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
 * branch + product in the actor's tenant; and the branch keeps a shelf count
 * for the product (a pos_branch_product row with a stock_qty) — writing one
 * here would also change where the product is offered.
 *
 * LAUNCH-P3 fix order 1, K3 — waste follows the selling rule (owner decision
 * 2026-10-02): it is NEVER refused on the shelf number. Wasting more than the
 * shelf shows is recorded, the shelf goes below zero, and {@see record()}
 * returns a warning saying so.
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
        return $this->record($branch, $product, $quantity, $reason, $actor, $notes, $occurredAt)['movement'];
    }

    /**
     * {@see handle()}, plus a warning when the waste took the shelf below zero.
     *
     * @return array{movement: ProductStockMovement, warning: ?string}
     */
    public function record(
        Branch $branch,
        Product $product,
        string|float|int $quantity,
        WasteReason $reason,
        User $actor,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
    ): array {
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
            : $product->theoreticalCost(perUnitPrecision: true);

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
        ): array {
            // Lock the branch shelf row INSIDE the transaction so the balance
            // the warning reports and the decrement are serialised against a
            // concurrent waste / sale.
            $shelf = DB::table('pos_branch_product')
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->value('stock_qty');
            if ($shelf === null) {
                // Not a stock-number refusal: the branch keeps no shelf count
                // for it (nothing was received or produced there), and
                // creating one would change where the product is offered.
                throw new RuntimeException(sprintf(
                    '"%s" has no shelf count at this branch — nothing has been received or produced here to waste.',
                    $product->name,
                ));
            }
            $currentBalance = (float) $shelf;

            $movement = $this->writeMovement->handle(
                product: $product,
                branch: $branch,
                type: ProductStockMovementType::Waste,
                // SIGNED — negative for the ledger / shelf decrement.
                quantity: '-'.number_format($absQty, 3, '.', ''),
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

            // LAUNCH-P3 K3 — sell-but-warn: recorded, and said when it went below zero.
            $after = round($currentBalance - $absQty, 3);
            $warning = $after < 0
                ? sprintf(
                    'Recorded. %s is now below zero on this branch\'s shelf: it showed %s and %s %s wasted, so it shows %s. Count it to correct it.',
                    $product->name,
                    number_format($currentBalance, 3, '.', ''),
                    number_format($absQty, 3, '.', ''),
                    $absQty == 1.0 ? 'was' : 'were',
                    number_format($after, 3, '.', ''),
                )
                : null;

            return ['movement' => $movement->fresh(['product', 'branch']), 'warning' => $warning];
        });
    }
}
