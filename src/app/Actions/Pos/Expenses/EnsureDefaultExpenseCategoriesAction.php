<?php

declare(strict_types=1);

namespace App\Actions\Pos\Expenses;

use App\Models\ExpenseCategory;

/**
 * Seed the default expense categories for a company the first time it touches
 * the expense-categories screen (nine: the original six + PD2 stock_purchases
 * + PD5 physical_items / delivery). Idempotent: returns early if the company
 * already has any category (so a deliberate prune isn't re-seeded after the
 * first row exists). No audit — these are system defaults, not a user edit.
 *
 * The default set mirrors the {@see \App\Enums\ExpenseCategory} fixed list so
 * previously logged expenses (storing those keys) resolve cleanly.
 */
final readonly class EnsureDefaultExpenseCategoriesAction
{
    /**
     * @var list<array{key: string, name: string, name_ar: string, sort_order: int}>
     */
    private const DEFAULTS = [
        ['key' => 'utilities', 'name' => 'Utilities', 'name_ar' => 'المرافق', 'sort_order' => 0],
        ['key' => 'supplies', 'name' => 'Supplies', 'name_ar' => 'اللوازم', 'sort_order' => 1],
        ['key' => 'ingredients', 'name' => 'Ingredients', 'name_ar' => 'المكوّنات', 'sort_order' => 2],
        ['key' => 'maintenance', 'name' => 'Maintenance', 'name_ar' => 'الصيانة', 'sort_order' => 3],
        ['key' => 'salaries', 'name' => 'Salaries', 'name_ar' => 'الرواتب', 'sort_order' => 4],
        ['key' => 'other', 'name' => 'Other', 'name_ar' => 'أخرى', 'sort_order' => 5],
        // PD2 — bought-in goods purchases (auto-logged by a stock receive
        // with a cost). Existing companies get it lazily from
        // ReceiveProductStockAction the first time they need it.
        ['key' => 'stock_purchases', 'name' => 'Stock purchases', 'name_ar' => 'مشتريات البضائع الجاهزة', 'sort_order' => 6],
        // PD5 — physical items + delivery get their own buckets; lazily added
        // for existing companies by RecordPurchaseExpenseAction.
        ['key' => 'physical_items', 'name' => 'Physical items', 'name_ar' => 'الأصناف المادية', 'sort_order' => 7],
        ['key' => 'delivery', 'name' => 'Delivery', 'name_ar' => 'التوصيل', 'sort_order' => 8],
    ];

    public function handle(int $companyId): void
    {
        $exists = ExpenseCategory::query()
            ->where('company_id', $companyId)
            ->exists();
        if ($exists) {
            return;
        }

        foreach (self::DEFAULTS as $default) {
            ExpenseCategory::query()->create([
                'company_id' => $companyId,
                'name' => $default['name'],
                'name_ar' => $default['name_ar'],
                'key' => $default['key'],
                'is_active' => true,
                'sort_order' => $default['sort_order'],
            ]);
        }
    }
}
