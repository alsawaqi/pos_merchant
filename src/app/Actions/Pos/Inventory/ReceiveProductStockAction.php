<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Pos\Expenses\RecordPurchaseExpenseAction;
use App\Enums\ExpenseCategory as ExpenseCategoryKey;
use App\Enums\ProductStockMovementType;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 — receive finished goods into a product's CENTRAL pool ("I have 50").
 * Positive inflow credited to pos_product_stock.quantity, before the merchant
 * allocates units out to branches.
 *
 * PD2/PD5 — bought-in goods are PURCHASES (buy, then sell), so a receive carries
 * the amount paid. The cash-out is booked inside the same transaction (via the
 * shared {@see RecordPurchaseExpenseAction}) and the movement's polymorphic
 * reference points at the item-cost expense row. branch_id stays NULL (the
 * central pool is a company-wide HQ resource, F5).
 *
 * PD5 — the category depends on the product: a PHYSICAL ITEM (cup, box, bulb)
 * books to 'physical_items', a bought-in sellable to 'stock_purchases'. An
 * optional delivery charge books a SECOND expense under 'delivery', so the
 * breakdown separates item cost from logistics. Both categories COUNT in the
 * cash-model net profit.
 */
final readonly class ReceiveProductStockAction
{
    public function __construct(
        private WriteProductStockMovementAction $writeMovement,
        private RecordPurchaseExpenseAction $recordExpense,
    ) {}

    public function handle(
        Product $product,
        string|float|int $quantity,
        ?string $note,
        User $actor,
        string|float|int|null $totalCost = null,
        string|float|int|null $deliveryCost = null,
        // PD6 — the accounting date the booked expenses are stamped with
        // (the Goods Received Note's received_at). NULL = now.
        ?Carbon $occurredAt = null,
    ): ProductStockMovement {
        if ((float) $quantity <= 0) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }

        $cost = $totalCost !== null && $totalCost !== '' ? (float) $totalCost : 0.0;
        $delivery = $deliveryCost !== null && $deliveryCost !== '' ? (float) $deliveryCost : 0.0;
        if ($cost < 0 || $delivery < 0) {
            throw new RuntimeException('The purchase cost cannot be negative.');
        }

        $companyId = (int) $product->company_id;
        // Physical items (cups/boxes/bulbs) book to their own category.
        $category = $product->is_internal
            ? ExpenseCategoryKey::PhysicalItems
            : ExpenseCategoryKey::StockPurchases;
        $label = $product->is_internal ? 'Physical-item purchase' : 'Stock purchase';

        return DB::transaction(function () use (
            $product, $quantity, $note, $actor, $cost, $delivery, $companyId, $category, $label, $occurredAt
        ): ProductStockMovement {
            $expense = null;
            if ($cost > 0) {
                $desc = sprintf(
                    '%s: %s x %s',
                    $label,
                    rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.'),
                    $product->name,
                );
                $expense = $this->recordExpense->handle(
                    companyId: $companyId,
                    branchId: null,
                    category: $category,
                    amount: $cost,
                    note: ($note !== null && $note !== '') ? $desc.' - '.$note : $desc,
                    actorUserId: (int) $actor->getKey(),
                    at: $occurredAt,
                );
            }

            // PD5 — delivery is a separate cash-out under 'delivery'.
            if ($delivery > 0) {
                $this->recordExpense->handle(
                    companyId: $companyId,
                    branchId: null,
                    category: ExpenseCategoryKey::Delivery,
                    amount: $delivery,
                    note: 'Delivery: '.$product->name,
                    actorUserId: (int) $actor->getKey(),
                    at: $occurredAt,
                );
            }

            return $this->writeMovement->handle(
                product: $product,
                branch: null,
                type: ProductStockMovementType::Received,
                quantity: $quantity,
                actor: $actor,
                note: $note,
                referenceType: $expense !== null ? Expense::class : null,
                referenceId: $expense?->id,
            );
        });
    }
}
