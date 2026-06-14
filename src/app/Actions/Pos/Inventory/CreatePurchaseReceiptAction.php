<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Pos\Expenses\RecordPurchaseExpenseAction;
use App\Enums\ExpenseCategory;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptCharge;
use App\Models\PurchaseReceiptLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PD6 — record a whole Goods Received Note (Saved Purchase Receipt) in one
 * atomic submit.
 *
 * The merchant's intent: one page, one delivery. Pick many items (ingredients +
 * ready/bought-in products + physical items) mixed freely, give each a quantity
 * + cost, optionally split each across branches right there, and add any number
 * of named extra charges. This composes the EXISTING per-item machinery rather
 * than duplicating it:
 *
 *   - every LINE fans out to the matching ReceiveAndDistribute action (the
 *     ingredient or product twin), which receives into the central warehouse,
 *     allocates the line's branch split, and books the categorized item-cost
 *     expense ('ingredients' / 'stock_purchases' / 'physical_items', chosen by
 *     the item). Whatever a line does not distribute stays central for later.
 *   - every named CHARGE books its OWN expense under its chosen category
 *     (default 'delivery'), via the shared RecordPurchaseExpenseAction.
 *
 * All of it — the receipt header, its lines, its charges, every stock movement
 * and every expense — lands in ONE outer transaction, so a bad line rolls the
 * whole receipt back (the inner receives nest via savepoints). The receipt's
 * received_at is threaded as the expense accounting date so a back-dated
 * delivery books into the right period of the cash-model P&L.
 */
final readonly class CreatePurchaseReceiptAction
{
    public function __construct(
        private ReceiveAndDistributeIngredientStockAction $receiveIngredient,
        private ReceiveAndDistributeProductStockAction $receiveProduct,
        private RecordPurchaseExpenseAction $recordExpense,
    ) {}

    /**
     * @param  list<array{
     *     item_type: string,
     *     ingredient?: Ingredient|null,
     *     product?: Product|null,
     *     quantity: string|float|int,
     *     line_cost: string|float|int,
     *     tax_amount?: string|float|int|null,
     *     tax_rate?: string|float|int|null,
     *     allocations: list<array{branch: Branch, quantity: string|float|int}>,
     * }>  $lines
     * @param  list<array{name: string, category: ExpenseCategory, amount: string|float|int, tax_amount?: string|float|int|null, tax_rate?: string|float|int|null}>  $charges
     */
    public function handle(
        int $companyId,
        ?Supplier $supplier,
        ?string $reference,
        ?Carbon $receivedAt,
        ?string $note,
        array $lines,
        array $charges,
        User $actor,
    ): PurchaseReceipt {
        $at = $receivedAt ?? now();

        return DB::transaction(function () use (
            $companyId, $supplier, $reference, $at, $note, $lines, $charges, $actor
        ): PurchaseReceipt {
            $receipt = PurchaseReceipt::query()->create([
                'company_id' => $companyId,
                'supplier_id' => $supplier?->id,
                'reference' => $reference,
                'items_total' => '0.000',
                'charges_total' => '0.000',
                'grand_total' => '0.000',
                'status' => 'received',
                'note' => $note,
                'recorded_by_user_id' => (int) $actor->getKey(),
                'received_at' => $at,
            ]);

            // PT — tax_total accumulates every line + charge tax; grand_total is
            // the gross (items + charges + tax = what was actually paid).
            $itemsTotal = 0.0;
            $taxTotal = 0.0;
            $order = 0;
            foreach ($lines as $line) {
                $r = $this->writeLine($receipt, $line, $note, $actor, $at, $order++);
                $itemsTotal += $r['cost'];
                $taxTotal += $r['tax'];
            }

            $chargesTotal = 0.0;
            $order = 0;
            foreach ($charges as $charge) {
                $r = $this->writeCharge($receipt, $charge, $companyId, $actor, $at, $order++);
                $chargesTotal += $r['amount'];
                $taxTotal += $r['tax'];
            }

            $receipt->update([
                'items_total' => number_format($itemsTotal, 3, '.', ''),
                'charges_total' => number_format($chargesTotal, 3, '.', ''),
                'tax_total' => number_format($taxTotal, 3, '.', ''),
                'grand_total' => number_format($itemsTotal + $chargesTotal + $taxTotal, 3, '.', ''),
            ]);

            return $receipt->fresh(['lines', 'charges', 'supplier', 'recordedByUser']);
        });
    }

    /**
     * Receive + distribute one line, snapshot it, and return its net cost + tax
     * so the header totals can be tallied.
     *
     * @param  array{
     *     item_type: string,
     *     ingredient?: Ingredient|null,
     *     product?: Product|null,
     *     quantity: string|float|int,
     *     line_cost: string|float|int,
     *     tax_amount?: string|float|int|null,
     *     tax_rate?: string|float|int|null,
     *     allocations: list<array{branch: Branch, quantity: string|float|int}>,
     * }  $line
     * @return array{cost: float, tax: float}
     */
    private function writeLine(
        PurchaseReceipt $receipt,
        array $line,
        ?string $note,
        User $actor,
        Carbon $at,
        int $order,
    ): array {
        $cost = (float) $line['line_cost'];
        // PT — tax paid on this line's item cost (on top of line_cost). No tax
        // on a free line (cost 0). tax_rate is the % when a rate was picked.
        $tax = $cost > 0 && isset($line['tax_amount']) ? (float) $line['tax_amount'] : 0.0;
        $taxRate = ($cost > 0 && isset($line['tax_rate']) && $line['tax_rate'] !== null && $line['tax_rate'] !== '')
            ? (float) $line['tax_rate']
            : null;
        // The line cost rides the single receive (0 = a free line, books no
        // expense); the allocation split is pure internal movement.
        $allocLines = array_map(
            static fn (array $a): array => ['branch' => $a['branch'], 'quantity' => $a['quantity']],
            $line['allocations'],
        );

        if ($line['item_type'] === 'ingredient') {
            /** @var Ingredient $ingredient */
            $ingredient = $line['ingredient'];
            $result = $this->receiveIngredient->handle(
                $ingredient,
                $line['quantity'],
                $allocLines,
                $note,
                $actor,
                $cost > 0 ? $cost : null,
                null,
                $at,
                taxAmount: $tax > 0 ? $tax : null,
                taxRate: $taxRate,
            );
            $movement = $result['received'];
            $itemName = (string) $ingredient->name;
            $unit = $ingredient->unit?->value;
            $ingredientId = (int) $ingredient->id;
            $productId = null;
            $category = ExpenseCategory::Ingredients->value;
        } else {
            /** @var Product $product */
            $product = $line['product'];
            $result = $this->receiveProduct->handle(
                $product,
                $line['quantity'],
                $allocLines,
                $note,
                $actor,
                $cost > 0 ? $cost : null,
                null,
                $at,
                taxAmount: $tax > 0 ? $tax : null,
                taxRate: $taxRate,
            );
            $movement = $result['received'];
            $itemName = (string) $product->name;
            $unit = null;
            $ingredientId = null;
            $productId = (int) $product->id;
            $category = $product->is_internal
                ? ExpenseCategory::PhysicalItems->value
                : ExpenseCategory::StockPurchases->value;
        }

        $expenseId = ($movement->reference_type === Expense::class)
            ? (int) $movement->reference_id
            : null;

        PurchaseReceiptLine::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'item_type' => $line['item_type'],
            'ingredient_id' => $ingredientId,
            'product_id' => $productId,
            'item_name' => $itemName,
            'quantity' => (string) $line['quantity'],
            'unit' => $unit,
            'line_cost' => number_format($cost, 3, '.', ''),
            'tax_amount' => number_format($tax, 3, '.', ''),
            'tax_rate' => $taxRate,
            'expense_category' => $cost > 0 ? $category : null,
            'allocations_json' => $this->snapshotAllocations($line['allocations']),
            'expense_id' => $expenseId,
            'display_order' => $order,
        ]);

        return ['cost' => $cost, 'tax' => $tax];
    }

    /**
     * Book one named charge as its own expense, snapshot it, and return its net
     * amount + tax.
     *
     * @param  array{name: string, category: ExpenseCategory, amount: string|float|int, tax_amount?: string|float|int|null, tax_rate?: string|float|int|null}  $charge
     * @return array{amount: float, tax: float}
     */
    private function writeCharge(
        PurchaseReceipt $receipt,
        array $charge,
        int $companyId,
        User $actor,
        Carbon $at,
        int $order,
    ): array {
        $amount = (float) $charge['amount'];
        // PT — tax paid on this charge (on top); no tax on a zero charge.
        $tax = $amount > 0 && isset($charge['tax_amount']) ? (float) $charge['tax_amount'] : 0.0;
        $taxRate = ($amount > 0 && isset($charge['tax_rate']) && $charge['tax_rate'] !== null && $charge['tax_rate'] !== '')
            ? (float) $charge['tax_rate']
            : null;
        $expense = null;
        if ($amount > 0) {
            $expense = $this->recordExpense->handle(
                companyId: $companyId,
                branchId: null,
                category: $charge['category'],
                amount: $amount + $tax,
                note: $charge['name'],
                actorUserId: (int) $actor->getKey(),
                at: $at,
                taxAmount: $tax,
                taxRate: $taxRate,
            );
        }

        PurchaseReceiptCharge::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'name' => $charge['name'],
            'expense_category' => $charge['category']->value,
            'amount' => number_format($amount, 3, '.', ''),
            'tax_amount' => number_format($tax, 3, '.', ''),
            'tax_rate' => $taxRate,
            'expense_id' => $expense?->id,
            'display_order' => $order,
        ]);

        return ['amount' => $amount, 'tax' => $tax];
    }

    /**
     * Freeze where a line was distributed so the document reads back even after
     * a branch is renamed.
     *
     * @param  list<array{branch: Branch, quantity: string|float|int}>  $allocations
     * @return list<array{branch_id: int, branch_uuid: string, branch_name: string, quantity: string}>|null
     */
    private function snapshotAllocations(array $allocations): ?array
    {
        if ($allocations === []) {
            return null;
        }

        return array_map(static fn (array $a): array => [
            'branch_id' => (int) $a['branch']->id,
            'branch_uuid' => (string) $a['branch']->uuid,
            'branch_name' => (string) $a['branch']->name,
            'quantity' => (string) $a['quantity'],
        ], $allocations);
    }
}
