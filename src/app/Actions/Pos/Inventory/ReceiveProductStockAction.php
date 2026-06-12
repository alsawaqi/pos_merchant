<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Pos\Expenses\EnsureDefaultExpenseCategoriesAction;
use App\Enums\ExpenseCategory as ExpenseCategoryKey;
use App\Enums\ExpenseStatus;
use App\Enums\ProductStockMovementType;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 — receive finished goods into a product's CENTRAL pool ("I have 50").
 * Positive inflow credited to pos_product_stock.quantity, before the merchant
 * allocates units out to branches.
 *
 * PD2 — bought-in goods are PURCHASES (buy, then sell), so a receive may carry
 * the total amount paid. When it does, the cash-out is booked as a
 * 'stock_purchases' expense inside the same transaction (mirroring the
 * ingredient RecordPurchaseAction precedent) and the movement's polymorphic
 * reference points at the expense row. Unlike 'ingredients', this category
 * COUNTS in the Sales report's operating expenses: unit products carry no
 * recipe snapshot, so their sales contribute zero COGS — the purchase expense
 * is the only path their cost has into net profit. branch_id stays NULL
 * (the central pool is a company-wide HQ resource, F5).
 */
final readonly class ReceiveProductStockAction
{
    public function __construct(
        private WriteProductStockMovementAction $writeMovement,
        private EnsureDefaultExpenseCategoriesAction $ensureDefaultCategories,
    ) {}

    public function handle(
        Product $product,
        string|float|int $quantity,
        ?string $note,
        User $actor,
        string|float|int|null $totalCost = null,
    ): ProductStockMovement {
        if ((float) $quantity <= 0) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }

        $cost = $totalCost !== null && $totalCost !== '' ? (float) $totalCost : 0.0;
        if ($cost < 0) {
            throw new RuntimeException('The purchase cost cannot be negative.');
        }

        return DB::transaction(function () use ($product, $quantity, $note, $actor, $cost): ProductStockMovement {
            $expense = $cost > 0
                ? $this->recordPurchaseExpense($product, $quantity, $note, $actor, $cost)
                : null;

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

    /**
     * The cash-out, for EXACTLY what was paid (the ingredient-purchase
     * pattern). Status Recorded — it enters the normal expenses review
     * queue and counts toward net profit unless rejected.
     */
    private function recordPurchaseExpense(
        Product $product,
        string|float|int $quantity,
        ?string $note,
        User $actor,
        float $cost,
    ): Expense {
        $companyId = (int) $product->company_id;
        $this->ensureCategoryExists($companyId);

        $desc = sprintf(
            'Stock purchase: %s x %s',
            rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.'),
            $product->name,
        );

        return Expense::query()->create([
            'company_id' => $companyId,
            // Central receive = a company-wide purchase, not one branch's.
            'branch_id' => null,
            'category' => ExpenseCategoryKey::StockPurchases->value,
            'amount' => number_format($cost, 3, '.', ''),
            'note' => ($note !== null && $note !== '') ? $desc.' - '.$note : $desc,
            'logged_by_portal_user_id' => $actor->getKey(),
            'logged_at' => now(),
            'status' => ExpenseStatus::Recorded->value,
        ]);
    }

    /**
     * Make sure the 'stock_purchases' display category resolves.
     *
     * A FRESH company (zero rows) gets the FULL default seed — inserting
     * only this one row would trip the seeder's any-row guard and
     * permanently suppress utilities/supplies/… for that company (the
     * portal dropdown and the device's expense screen would offer only
     * "Stock purchases" forever).
     *
     * A company that already has rows (seeded pre-PD2) gets just this key,
     * lazily — withTrashed so a deliberately deleted row is respected, not
     * duplicated into the (company, key) unique index.
     */
    private function ensureCategoryExists(int $companyId): void
    {
        $hasAny = ExpenseCategory::withTrashed()
            ->where('company_id', $companyId)
            ->exists();
        if (! $hasAny) {
            $this->ensureDefaultCategories->handle($companyId);

            return;
        }

        $exists = ExpenseCategory::withTrashed()
            ->where('company_id', $companyId)
            ->where('key', ExpenseCategoryKey::StockPurchases->value)
            ->exists();
        if ($exists) {
            return;
        }

        ExpenseCategory::query()->create([
            'company_id' => $companyId,
            'name' => 'Stock purchases',
            'name_ar' => 'مشتريات البضائع الجاهزة',
            'key' => ExpenseCategoryKey::StockPurchases->value,
            'is_active' => true,
            'sort_order' => 6,
        ]);
    }
}
