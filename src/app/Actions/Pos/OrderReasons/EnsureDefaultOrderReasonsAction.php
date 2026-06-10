<?php

declare(strict_types=1);

namespace App\Actions\Pos\OrderReasons;

use App\Models\CompReason;
use App\Models\VoidReason;

/**
 * Phase B — seed the Additions doc's default void + comp reason codes
 * for a company the first time it opens the settings screen. Mirrors
 * {@see \App\Actions\Pos\Expenses\EnsureDefaultExpenseCategoriesAction}:
 * idempotent per list (returns early once ANY row exists, so a
 * deliberate prune is never re-seeded). The pos_admin migration
 * backfilled existing companies; this covers companies created after
 * it ran. No audit — system defaults, not a user edit.
 */
final readonly class EnsureDefaultOrderReasonsAction
{
    /**
     * @var list<array{code: string, name: string, name_ar: string, inv: bool, mgr: bool}>
     */
    private const VOID_DEFAULTS = [
        ['code' => 'change_of_mind', 'name' => 'Customer Change of Mind', 'name_ar' => 'تغيير رأي الزبون', 'inv' => true, 'mgr' => false],
        ['code' => 'wrong_order_entry', 'name' => 'Wrong Order Entry', 'name_ar' => 'إدخال طلب خاطئ', 'inv' => false, 'mgr' => false],
        ['code' => 'wrong_item_prepared', 'name' => 'Wrong Item Prepared', 'name_ar' => 'تحضير صنف خاطئ', 'inv' => true, 'mgr' => true],
        ['code' => 'quality_issue', 'name' => 'Quality Issue', 'name_ar' => 'مشكلة جودة', 'inv' => true, 'mgr' => true],
        ['code' => 'allergen_dietary', 'name' => 'Allergen / Dietary', 'name_ar' => 'حساسية / نظام غذائي', 'inv' => true, 'mgr' => true],
        ['code' => 'overcooked_undercooked', 'name' => 'Overcooked / Undercooked', 'name_ar' => 'إفراط / نقص في الطهي', 'inv' => true, 'mgr' => true],
        ['code' => 'out_of_stock', 'name' => 'Out of Stock', 'name_ar' => 'نفاد المخزون', 'inv' => false, 'mgr' => true],
        ['code' => 'training', 'name' => 'Training', 'name_ar' => 'تدريب', 'inv' => false, 'mgr' => false],
        ['code' => 'manager_comp', 'name' => 'Manager Comp', 'name_ar' => 'ضيافة الإدارة', 'inv' => true, 'mgr' => true],
    ];

    /**
     * @var list<array{code: string, name: string, name_ar: string}>
     */
    private const COMP_DEFAULTS = [
        ['code' => 'long_wait', 'name' => 'Long Wait', 'name_ar' => 'انتظار طويل'],
        ['code' => 'service_recovery', 'name' => 'Service Recovery', 'name_ar' => 'تعويض عن الخدمة'],
        ['code' => 'vip_hospitality', 'name' => 'VIP Hospitality', 'name_ar' => 'ضيافة كبار الزوار'],
        ['code' => 'staff_meal', 'name' => 'Staff Meal', 'name_ar' => 'وجبة موظفين'],
        ['code' => 'tasting', 'name' => 'Tasting', 'name_ar' => 'تذوق'],
        ['code' => 'owner_discretion', 'name' => 'Owner Discretion', 'name_ar' => 'تقدير المالك'],
    ];

    public function handle(int $companyId): void
    {
        if (! VoidReason::query()->where('company_id', $companyId)->exists()) {
            foreach (self::VOID_DEFAULTS as $i => $d) {
                VoidReason::query()->create([
                    'company_id' => $companyId,
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'name_ar' => $d['name_ar'],
                    'affects_inventory' => $d['inv'],
                    'requires_manager' => $d['mgr'],
                    'is_active' => true,
                    'sort_order' => $i,
                ]);
            }
        }

        if (! CompReason::query()->where('company_id', $companyId)->exists()) {
            foreach (self::COMP_DEFAULTS as $i => $d) {
                CompReason::query()->create([
                    'company_id' => $companyId,
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'name_ar' => $d['name_ar'],
                    'max_amount' => null,
                    'is_active' => true,
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
