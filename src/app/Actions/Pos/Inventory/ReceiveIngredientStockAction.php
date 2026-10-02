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
use App\Support\StockDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G4 — receive an ingredient purchase into the company's CENTRAL warehouse
 * ("100 kg of sugar arrived"), the ingredient twin of
 * {@see ReceiveProductStockAction}. Positive inflow credited to
 * pos_ingredient_stock.quantity, before the merchant allocates stock out to
 * branches. Quantity is in the ingredient's BASE unit.
 *
 * PD5 — buying ingredients is a PURCHASE, so a receive carries the amount paid:
 * the cash-out books an 'ingredients' expense (+ an optional 'delivery' expense)
 * inside the same transaction (the shared {@see RecordPurchaseExpenseAction}).
 * In the cash model the purchase is what hits net profit.
 *
 * LAUNCH-P2 P2-2 (M9) — the received movement is stamped with the price
 * ACTUALLY PAID per base unit (6 decimals; the line's own unit price when the
 * goods-received note gave one, else total cost ÷ quantity), and that price
 * is blended into the ingredient's weighted-average cost
 * ({@see ApplyWeightedAverageCostAction}) before the stock lands. A line with
 * nothing paid (free sample, correction, opening stock) is stamped at 0 and
 * leaves the average unchanged. Delivery stays a separate expense, never part
 * of the cost. A back-dated receipt dates the movement at the receipt date
 * (the caller passes it).
 */
final readonly class ReceiveIngredientStockAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
        private RecordPurchaseExpenseAction $recordExpense,
        private ApplyWeightedAverageCostAction $averageCost,
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
        return $this->receive(
            $ingredient, $quantity, $note, $actor, $totalCost, $deliveryCost,
            $occurredAt, $taxAmount, $taxRate,
        )['movement'];
    }

    /**
     * The full receive. Optional LAUNCH-P2 inputs:
     *   $movementAt      when the stock arrived (the movement's occurred_at);
     *                    NULL = $occurredAt, else now
     *   $paidUnitCost    the price paid per BASE unit; NULL = total ÷ quantity
     *   $referenceType/Id  what the received movement points at (a receipt
     *                    line); NULL = the booked expense, as before
     *   $costContext     extra audit context for the cost change
     *
     * @param  array<string, mixed>  $costContext
     * @return array{movement: StockMovement, expense: Expense|null, unit_cost: string}
     */
    public function receive(
        Ingredient $ingredient,
        string|float|int $quantity,
        ?string $note,
        User $actor,
        string|float|int|null $totalCost = null,
        string|float|int|null $deliveryCost = null,
        ?Carbon $occurredAt = null,
        string|float|int|null $taxAmount = null,
        string|float|int|null $taxRate = null,
        ?Carbon $movementAt = null,
        string|float|int|null $paidUnitCost = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $costContext = [],
    ): array {
        // Ledger precision once: the movement, the average and the expense
        // text all use the same 4-decimal base quantity.
        $quantity = (string) StockDecimal::quantity($quantity);
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

        // The price actually paid per base unit, never rounded below 6dp.
        $paid = $paidUnitCost !== null && $paidUnitCost !== ''
            ? ApplyWeightedAverageCostAction::decimal($paidUnitCost)
                ->toScale(StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP)
            : ApplyWeightedAverageCostAction::decimal($cost)
                ->dividedBy(ApplyWeightedAverageCostAction::decimal($quantity), StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP);
        if ($paid->isNegative()) {
            throw new RuntimeException('The purchase cost cannot be negative.');
        }
        $paidText = (string) StockDecimal::unitCost((string) $paid);

        $companyId = (int) $ingredient->company_id;

        return DB::transaction(function () use (
            $ingredient, $quantity, $note, $actor, $cost, $delivery, $tax, $taxRatePct, $companyId,
            $occurredAt, $movementAt, $paidText, $referenceType, $referenceId, $costContext
        ): array {
            $expense = null;
            if ($cost > 0) {
                $desc = sprintf(
                    'Ingredient purchase: %s %s of %s',
                    (string) StockDecimal::format($quantity, 0, StockDecimal::QUANTITY_SCALE),
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

            // M9 — blend the paid price into the average BEFORE the stock
            // lands (on-hand excludes this receipt), under the row lock. A
            // line with nothing paid (a free sample, a correction, opening
            // stock already paid for) carries no price information: it is
            // stamped at 0 — no purchase spend — and leaves the average alone.
            if (ApplyWeightedAverageCostAction::decimal($paidText)->isPositive()) {
                $this->averageCost->handle($ingredient, $quantity, $paidText, $actor, array_merge([
                    'source' => 'received',
                ], $costContext));
            }

            $movement = $this->writeMovement->handle(
                branch: null,
                ingredient: $ingredient,
                type: StockMovementType::Received,
                quantity: $quantity,
                unitCostAtTime: $paidText,
                actor: $actor,
                note: $note,
                referenceType: $referenceType ?? ($expense !== null ? Expense::class : null),
                referenceId: $referenceType !== null ? $referenceId : $expense?->id,
                occurredAt: $movementAt ?? $occurredAt,
            );

            return ['movement' => $movement, 'expense' => $expense, 'unit_cost' => $paidText];
        });
    }
}
