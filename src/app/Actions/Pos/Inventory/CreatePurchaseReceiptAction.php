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
use App\Support\StockDecimal;
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
 *
 * LAUNCH-P2 — the one way stock comes in for the pilot:
 *   - P2-2 every ingredient line stamps its stock movement with the price
 *     actually paid per base unit and updates the weighted-average cost; a
 *     back-dated receipt dates its movements (receive + allocations) at the
 *     receipt date; the received movement points at its receipt line, so the
 *     purchasing report credits the receipt's supplier.
 *   - P2-3 a line may be entered in a purchase unit (kg, box, the piece unit)
 *     with a price per that unit; the controller converts to base units and
 *     the line keeps how it was entered (purchase_unit / purchase_quantity /
 *     unit_price) plus the per-base-unit cost (unit_cost).
 */
final readonly class CreatePurchaseReceiptAction
{
    public function __construct(
        private ReceiveAndDistributeIngredientStockAction $receiveIngredient,
        private ReceiveAndDistributeProductStockAction $receiveProduct,
        private RecordPurchaseExpenseAction $recordExpense,
        private ContainerBreakdownAction $breakdown,
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
        bool $isCredit = false,
        ?Carbon $dueDate = null,
        ?Branch $destinationBranch = null,
    ): PurchaseReceipt {
        $at = $receivedAt ?? now();
        // LAUNCH-P2 P2-2 — when the stock arrived: a back-dated receipt dates
        // its movements at the receipt date; a receipt dated today (or later)
        // lands now, so a count earlier today never mistakes it for stock that
        // was already on the shelf.
        $movementAt = ($receivedAt === null || $receivedAt->isToday() || $receivedAt->isFuture())
            ? now()
            : $receivedAt->copy();

        // Phase B — direct-to-branch delivery (owner scenario 3: the supplier
        // van drops the goods at the branch). Every line auto-allocates its
        // FULL quantity to the destination through the existing receive+
        // distribute pipeline, so the ledger stays conserved and the expense/
        // AP behaviour is byte-identical to a central receipt. Callers must
        // not mix explicit per-line splits with a destination (the controller
        // rejects that shape); overriding here keeps the semantics with the
        // atomic writer whatever the caller.
        if ($destinationBranch !== null) {
            $lines = array_map(static function (array $line) use ($destinationBranch): array {
                $line['allocations'] = [[
                    'branch' => $destinationBranch,
                    'quantity' => $line['quantity'],
                    // LAUNCH review add-on (B3) — the whole breakdown moves too.
                    'leaves' => $line['leaves'] ?? [],
                    'pieces' => $line['pieces'] ?? null,
                ]];

                return $line;
            }, $lines);
        }

        return DB::transaction(function () use (
            $companyId, $supplier, $reference, $at, $movementAt, $note, $lines, $charges, $actor, $isCredit, $dueDate, $destinationBranch
        ): PurchaseReceipt {
            $receipt = PurchaseReceipt::query()->create([
                'company_id' => $companyId,
                'supplier_id' => $supplier?->id,
                'destination_branch_id' => $destinationBranch?->id,
                'reference' => $reference,
                'items_total' => '0.000',
                'charges_total' => '0.000',
                'grand_total' => '0.000',
                'status' => 'received',
                // AP — bought on credit means nothing is paid yet; a cash buy is
                // settled in full at receive. amount_paid + payment_status are
                // finalised below once grand_total is known. The cost still books
                // its expense at receive regardless — credit only defers CASH.
                'is_credit' => $isCredit,
                'due_date' => $isCredit ? $dueDate : null,
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
                $r = $this->writeLine($receipt, $line, $note, $actor, $at, $movementAt, $order++);
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

            $grandTotal = $itemsTotal + $chargesTotal + $taxTotal;
            // AP — a cash buy is fully paid at receive; a credit buy starts at 0
            // owed-but-paid. A zero-total credit buy (all free lines) owes
            // nothing, so it is already 'paid'.
            $amountPaid = $isCredit ? 0.0 : $grandTotal;
            $paymentStatus = $amountPaid >= $grandTotal - 1e-9
                ? 'paid'
                : ($amountPaid > 1e-9 ? 'partial' : 'unpaid');

            $receipt->update([
                'items_total' => number_format($itemsTotal, 3, '.', ''),
                'charges_total' => number_format($chargesTotal, 3, '.', ''),
                'tax_total' => number_format($taxTotal, 3, '.', ''),
                'grand_total' => number_format($grandTotal, 3, '.', ''),
                'amount_paid' => number_format($amountPaid, 3, '.', ''),
                'payment_status' => $paymentStatus,
            ]);

            return $receipt->fresh(['lines', 'charges', 'supplier', 'recordedByUser', 'destinationBranch']);
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
     *     purchase_unit?: string|null,
     *     purchase_quantity?: string|float|int|null,
     *     unit_price?: string|float|int|null,
     *     paid_unit_cost?: string|null,
     * }  $line
     * @return array{cost: float, tax: float}
     */
    private function writeLine(
        PurchaseReceipt $receipt,
        array $line,
        ?string $note,
        User $actor,
        Carbon $at,
        Carbon $movementAt,
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

        $isIngredient = $line['item_type'] === 'ingredient';

        // LAUNCH-P2 — the line row exists FIRST so the received movement can
        // point at it: the purchasing report credits a receipt's spend to the
        // receipt's supplier through this link.
        /** @var PurchaseReceiptLine $receiptLine */
        $receiptLine = PurchaseReceiptLine::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'item_type' => $line['item_type'],
            'ingredient_id' => $isIngredient ? (int) $line['ingredient']->id : null,
            'product_id' => $isIngredient ? null : (int) $line['product']->id,
            'item_name' => (string) ($isIngredient ? $line['ingredient']->name : $line['product']->name),
            'quantity' => $isIngredient ? StockDecimal::quantity($line['quantity']) : (string) $line['quantity'],
            'unit' => $isIngredient ? $line['ingredient']->unit?->value : null,
            'line_cost' => number_format($cost, 3, '.', ''),
            'tax_amount' => number_format($tax, 3, '.', ''),
            'tax_rate' => $taxRate,
            'expense_category' => null,
            'allocations_json' => $this->snapshotAllocations($line['allocations']),
            'expense_id' => null,
            'display_order' => $order,
            'purchase_unit' => $line['purchase_unit'] ?? null,
            'purchase_quantity' => isset($line['purchase_quantity']) ? StockDecimal::quantity($line['purchase_quantity']) : null,
            'unit_price' => isset($line['unit_price']) ? StockDecimal::unitCost($line['unit_price']) : null,
            // LAUNCH review add-on (C1, D3) — the container / pack it was bought
            // in, its label + size as they stood, and how many.
            'container_id' => isset($line['container']) ? (int) $line['container']->id : null,
            'pack_id' => isset($line['pack']) ? (int) $line['pack']->id : null,
            'container_label' => $line['container_label'] ?? null,
            'container_factor' => $line['container_factor'] ?? null,
            'pieces' => $line['pieces'] ?? null,
        ]);

        $paidUnitCost = null;
        if ($isIngredient) {
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
                movementAt: $movementAt,
                paidUnitCost: $line['paid_unit_cost'] ?? null,
                referenceType: PurchaseReceiptLine::class,
                referenceId: (int) $receiptLine->id,
                costContext: [
                    'purchase_receipt_id' => (int) $receipt->id,
                    'purchase_receipt_uuid' => (string) $receipt->uuid,
                    'purchase_receipt_line_id' => (int) $receiptLine->id,
                    'supplier_id' => $receipt->supplier_id,
                ],
            );
            $expenseId = $result['expense']?->id;
            $paidUnitCost = $result['unit_cost'];
            $category = ExpenseCategory::Ingredients->value;

            // LAUNCH review add-on (B3) — the breakdown: the containers land at
            // the warehouse, then each branch share moves with its stock.
            $leaves = $line['leaves'] ?? [];
            if ($leaves !== []) {
                $ref = [
                    'reference_type' => PurchaseReceiptLine::class,
                    'reference_id' => (int) $receiptLine->id,
                    'occurred_at' => $movementAt,
                ];
                $this->breakdown->add($ingredient, null, $leaves, 'purchase', $actor, $ref + ['stock_movement_id' => (int) $result['received']->id]);
                foreach (array_values($line['allocations']) as $i => $allocation) {
                    $shareLeaves = $allocation['leaves'] ?? [];
                    if ($shareLeaves === []) {
                        continue;
                    }
                    $this->breakdown->move($ingredient, null, (int) $allocation['branch']->id, $shareLeaves, 'allocation_out', 'allocation_in', $actor, $ref + [
                        'to_stock_movement_id' => isset($result['allocations'][$i]) ? (int) $result['allocations'][$i]->id : null,
                    ]);
                }
            }
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
            $expenseId = ($movement->reference_type === Expense::class)
                ? (int) $movement->reference_id
                : null;
            $category = $product->is_internal
                ? ExpenseCategory::PhysicalItems->value
                : ExpenseCategory::StockPurchases->value;
        }

        $receiptLine->forceFill([
            'expense_category' => $cost > 0 ? $category : null,
            'expense_id' => $expenseId,
            'unit_cost' => $paidUnitCost,
        ])->save();

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
        ] + (isset($a['pieces']) ? ['pieces' => (string) $a['pieces']] : []), $allocations);
    }
}
