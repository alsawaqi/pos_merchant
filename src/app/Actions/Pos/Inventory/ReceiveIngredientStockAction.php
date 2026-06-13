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
    ): StockMovement {
        if ((float) $quantity <= 0) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }

        $cost = $totalCost !== null && $totalCost !== '' ? (float) $totalCost : 0.0;
        $delivery = $deliveryCost !== null && $deliveryCost !== '' ? (float) $deliveryCost : 0.0;
        if ($cost < 0 || $delivery < 0) {
            throw new RuntimeException('The purchase cost cannot be negative.');
        }

        $companyId = (int) $ingredient->company_id;

        return DB::transaction(function () use (
            $ingredient, $quantity, $note, $actor, $cost, $delivery, $companyId
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
                    amount: $cost,
                    note: trim(($note !== null && $note !== '') ? $desc.' - '.$note : $desc),
                    actorUserId: (int) $actor->getKey(),
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
