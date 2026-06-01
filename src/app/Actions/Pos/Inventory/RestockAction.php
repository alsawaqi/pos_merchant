<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5a — log a purchase / restock inflow.
 *
 * Positive quantity only (use AdjustStockAction to correct
 * a wrong-direction restock so the original ledger row stays
 * intact). Optional supplier_id captures who we bought from
 * for the Phase 7 supplier-performance report. Optional
 * unit_cost lets the merchant override the ingredient's
 * default (prices fluctuate per purchase).
 *
 * Phase 5c will add a higher-level RestockRequest workflow
 * (POS staff submits → merchant approves → fulfilment writes
 * the actual movement); this Action is the underlying
 * inflow primitive.
 */
final readonly class RestockAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
    ) {}

    /**
     * @param  string|float|int       $quantity   Positive only
     * @param  string|float|int|null  $unitCost   NULL = use ingredient's default_unit_cost
     */
    public function handle(
        Branch $branch,
        Ingredient $ingredient,
        string|float|int $quantity,
        string|float|int|null $unitCost,
        ?Supplier $supplier,
        ?string $note,
        User $actor,
    ): StockMovement {
        if ((float) $quantity <= 0) {
            throw new RuntimeException('Restock quantity must be positive.');
        }

        // Optional supplier reference — verify it's ours.
        // Cross-tenant supplier would silently associate the
        // inflow with someone else's purchasing record.
        if ($supplier !== null && (int) $supplier->company_id !== (int) $branch->company_id) {
            throw new RuntimeException('Supplier does not belong to your company.');
        }

        $effectiveCost = $unitCost ?? $ingredient->default_unit_cost ?? 0;

        return DB::transaction(function () use ($branch, $ingredient, $quantity, $effectiveCost, $supplier, $note, $actor): StockMovement {
            $movement = $this->writeMovement->handle(
                branch: $branch,
                ingredient: $ingredient,
                type: StockMovementType::Restock,
                quantity: $quantity,
                unitCostAtTime: $effectiveCost,
                referenceType: $supplier !== null ? Supplier::class : null,
                referenceId: $supplier?->id,
                actor: $actor,
                note: $note,
            );

            // Also record the purchase as an expense so the cash-out counts
            // toward gross / net profit (category 'ingredients'). amount =
            // quantity x unit cost; skipped when there is no cost (a 0-OMR
            // expense is just noise). The per-ingredient purchases report
            // reads the stock_movements ledger directly, not this row.
            $amount = (float) $quantity * (float) $effectiveCost;
            if ($amount > 0) {
                $unit = $ingredient->unit?->value ?? '';
                $desc = trim(sprintf('Ingredient purchase: %s %s of %s', (float) $quantity, $unit, $ingredient->name));
                Expense::query()->create([
                    'company_id' => $branch->company_id,
                    'branch_id' => $branch->id,
                    'category' => ExpenseCategory::Ingredients->value,
                    'amount' => number_format($amount, 3, '.', ''),
                    'note' => ($note !== null && $note !== '') ? $desc.' - '.$note : $desc,
                    'logged_by_portal_user_id' => $actor->getKey(),
                    'logged_at' => $movement->occurred_at,
                    'status' => ExpenseStatus::Recorded->value,
                ]);
            }

            return $movement;
        });
    }
}
