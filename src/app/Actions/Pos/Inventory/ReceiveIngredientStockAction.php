<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Pos\Expenses\RecordPurchaseExpenseAction;
use App\Enums\ExpenseCategory as ExpenseCategoryKey;
use App\Enums\StockMovementType;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G4 — receive an ingredient purchase into the company's CENTRAL warehouse
 * ("100 kg of sugar arrived"), the ingredient twin of
 * {@see ReceiveProductStockAction}. Positive inflow credited to
 * pos_ingredient_stock.quantity, before the merchant allocates stock out to
 * branches. Quantity is in the ingredient's BASE unit. The ledger row
 * snapshots the ingredient's default unit cost so COGS reads stay consistent.
 *
 * PD5 — buying ingredients is a PURCHASE, so a receive carries the amount paid:
 * the cash-out books an 'ingredients' expense (+ an optional 'delivery' expense)
 * inside the same transaction (the shared {@see RecordPurchaseExpenseAction}),
 * and the movement references the item-cost expense. This closes the biggest
 * gap — the merchant's primary "bulk-buy ingredients → distribute" flow used to
 * book ZERO expense. In the cash model the purchase is what hits net profit.
 */
final readonly class ReceiveIngredientStockAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
        private RecordPurchaseExpenseAction $recordExpense,
    ) {}

    public function handle(
        Ingredient $ingredient,
        string|float|int $quantity,
        ?string $note,
        User $actor,
        string|float|int|null $totalCost = null,
        string|float|int|null $deliveryCost = null,
        // PD6 — the accounting date the booked expenses are stamped with
        // (the Goods Received Note's received_at). NULL = now.
        ?Carbon $occurredAt = null,
        // PT — optional tax PAID on the item cost (on top of $totalCost); the
        // booked expense's amount becomes the gross (cost + tax). NULL = none.
        string|float|int|null $taxAmount = null,
        string|float|int|null $taxRate = null,
    ): StockMovement {
        if ((float) $quantity <= 0) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }

        $cost = $totalCost !== null && $totalCost !== '' ? (float) $totalCost : 0.0;
        $delivery = $deliveryCost !== null && $deliveryCost !== '' ? (float) $deliveryCost : 0.0;
        $tax = $taxAmount !== null && $taxAmount !== '' ? (float) $taxAmount : 0.0;
        $taxRatePct = $taxRate !== null && $taxRate !== '' ? (float) $taxRate : null;
        if ($cost < 0 || $delivery < 0 || $tax < 0) {
            throw new RuntimeException('The purchase cost cannot be negative.');
        }

        $companyId = (int) $ingredient->company_id;

        return DB::transaction(function () use (
            $ingredient, $quantity, $note, $actor, $cost, $delivery, $tax, $taxRatePct, $companyId, $occurredAt
        ): StockMovement {
            $expense = null;
            if ($cost > 0) {
                $desc = sprintf(
                    'Ingredient purchase: %s %s of %s',
                    rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.'),
                    $ingredient->unit?->value ?? '',
                    $ingredient->name,
                );
                $expense = $this->recordExpense->handle(
                    companyId: $companyId,
                    branchId: null,
                    category: ExpenseCategoryKey::Ingredients,
                    amount: $cost + $tax,
                    note: trim(($note !== null && $note !== '') ? $desc.' - '.$note : $desc),
                    actorUserId: (int) $actor->getKey(),
                    at: $occurredAt,
                    taxAmount: $tax,
                    taxRate: $taxRatePct,
                );
            }

            if ($delivery > 0) {
                $this->recordExpense->handle(
                    companyId: $companyId,
                    branchId: null,
                    category: ExpenseCategoryKey::Delivery,
                    amount: $delivery,
                    note: 'Delivery: '.$ingredient->name,
                    actorUserId: (int) $actor->getKey(),
                    at: $occurredAt,
                );
            }

            return $this->writeMovement->handle(
                branch: null,
                ingredient: $ingredient,
                type: StockMovementType::Received,
                quantity: $quantity,
                unitCostAtTime: $ingredient->default_unit_cost ?? 0,
                actor: $actor,
                note: $note,
                referenceType: $expense !== null ? Expense::class : null,
                referenceId: $expense?->id,
            );
        });
    }
}
