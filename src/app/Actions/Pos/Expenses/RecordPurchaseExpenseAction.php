<?php

declare(strict_types=1);

namespace App\Actions\Pos\Expenses;

use App\Enums\ExpenseCategory as ExpenseCategoryKey;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Support\Carbon;

/**
 * PD5 — book a categorized PURCHASE expense for "money out the moment stock is
 * bought" (the cash model). The shared home for the cash-out every stock-inflow
 * action used to inline (the PD2 ReceiveProductStockAction precedent): create
 * the pos_expenses row (status Recorded → it counts toward net profit unless
 * rejected) AND lazily make sure its display category resolves.
 *
 * Used by the central ingredient + product receives (and their
 * receive-and-distribute twins) for both the item cost and the delivery split.
 * branch_id NULL = a company-wide (HQ) purchase; a branch id = that branch's
 * buy. (The per-branch RecordPurchaseAction still books its own inline
 * 'ingredients' expense — it predates this shared helper.)
 */
final readonly class RecordPurchaseExpenseAction
{
    /**
     * key → [name, name_ar, sort_order] for lazy category creation on a company
     * that already has rows (mirrors EnsureDefaultExpenseCategoriesAction).
     *
     * @var array<string, array{name: string, name_ar: string, sort_order: int}>
     */
    private const LABELS = [
        'ingredients' => ['name' => 'Ingredients', 'name_ar' => 'المكوّنات', 'sort_order' => 2],
        'stock_purchases' => ['name' => 'Stock purchases', 'name_ar' => 'مشتريات البضائع الجاهزة', 'sort_order' => 6],
        'physical_items' => ['name' => 'Physical items', 'name_ar' => 'الأصناف المادية', 'sort_order' => 7],
        'delivery' => ['name' => 'Delivery', 'name_ar' => 'التوصيل', 'sort_order' => 8],
    ];

    public function __construct(
        private EnsureDefaultExpenseCategoriesAction $ensureDefaultCategories,
    ) {}

    /**
     * @param  int  $actorUserId  the portal user who logged it
     */
    /**
     * @param  float  $amount  the GROSS paid (net + tax); the cash-model expense.
     * @param  float  $taxAmount  PT — the tax portion of $amount (0 = untaxed).
     * @param  float|null  $taxRate  PT — the % rate used (NULL = a typed amount).
     */
    public function handle(
        int $companyId,
        ?int $branchId,
        ExpenseCategoryKey $category,
        float $amount,
        string $note,
        int $actorUserId,
        ?Carbon $at = null,
        float $taxAmount = 0.0,
        ?float $taxRate = null,
    ): Expense {
        $this->ensureCategoryExists($companyId, $category);

        return Expense::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'category' => $category->value,
            'amount' => number_format($amount, 3, '.', ''),
            'tax_amount' => number_format($taxAmount, 3, '.', ''),
            'tax_rate' => $taxRate,
            'note' => $note,
            'logged_by_portal_user_id' => $actorUserId,
            'logged_at' => $at ?? now(),
            'status' => ExpenseStatus::Recorded->value,
        ]);
    }

    /**
     * A FRESH company (zero rows) gets the FULL default seed — inserting only
     * one row would trip the seeder's any-row guard and permanently suppress
     * the rest. A company that already has rows gets just this key, lazily
     * (withTrashed so a deliberately deleted row is respected, not duplicated
     * into the (company, key) unique index).
     */
    private function ensureCategoryExists(int $companyId, ExpenseCategoryKey $category): void
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
            ->where('key', $category->value)
            ->exists();
        if ($exists) {
            return;
        }

        $label = self::LABELS[$category->value] ?? [
            'name' => ucfirst(str_replace('_', ' ', $category->value)),
            'name_ar' => $category->value,
            'sort_order' => 9,
        ];
        ExpenseCategory::query()->create([
            'company_id' => $companyId,
            'name' => $label['name'],
            'name_ar' => $label['name_ar'],
            'key' => $category->value,
            'is_active' => true,
            'sort_order' => $label['sort_order'],
        ]);
    }
}
