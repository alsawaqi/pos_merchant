<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MerchantPermission;

/**
 * Canonical, UI-renderable view of the merchant permission set.
 *
 * The {@see MerchantPermission} enum holds the string keys (the
 * source of truth for what the server accepts). This catalog
 * decorates each key with:
 *
 *   - the domain it belongs to (Portal Users / POS Staff /
 *     Branches / Roles / etc.) — used to group checkboxes in
 *     the role builder
 *   - a UI label (one short verb-phrase per permission, both
 *     English and Arabic) — shown next to the checkbox
 *
 * Why a separate class and not enum methods:
 *   The enum cases are tied to spatie's permission lookup
 *   (the value string IS the contract). Adding label metadata
 *   to the cases would either bloat the enum or fight PHP's
 *   enum value typing. Keeping metadata in a separate Support
 *   class lets us evolve labels (i18n changes, blueprint
 *   reorganisations) without touching the permission lookup
 *   path.
 *
 * Frontend consumes this via GET /api/permissions/catalog and
 * renders the role-editor accordingly.
 */
final class PermissionCatalog
{
    /**
     * Domain → ordered list of permission descriptors. Order
     * matters for UI presentation (Portal Users first because
     * it's where you bootstrap the team, Roles last because
     * it's the meta-control).
     *
     * @return array<int, array{
     *     key: string,
     *     label_en: string,
     *     label_ar: string,
     *     permissions: list<array{
     *         key: string,
     *         label_en: string,
     *         label_ar: string,
     *     }>,
     * }>
     */
    public static function merchant(): array
    {
        return [
            [
                'key' => 'portal_users',
                'label_en' => 'Portal Users',
                'label_ar' => 'مستخدمو البوابة',
                'permissions' => [
                    [
                        'key' => MerchantPermission::PortalUsersView->value,
                        'label_en' => 'See the portal users list',
                        'label_ar' => 'عرض قائمة مستخدمي البوابة',
                    ],
                    [
                        'key' => MerchantPermission::PortalUsersInvite->value,
                        'label_en' => 'Create + reset password',
                        'label_ar' => 'إنشاء مستخدمين وإعادة تعيين كلمة المرور',
                    ],
                    [
                        'key' => MerchantPermission::PortalUsersUpdate->value,
                        'label_en' => 'Edit details + role',
                        'label_ar' => 'تعديل البيانات والأدوار',
                    ],
                    [
                        'key' => MerchantPermission::PortalUsersRevoke->value,
                        'label_en' => 'Suspend + reactivate',
                        'label_ar' => 'إيقاف وإعادة تفعيل',
                    ],
                ],
            ],
            [
                'key' => 'pos_staff',
                'label_en' => 'POS Staff',
                'label_ar' => 'موظفو نقاط البيع',
                'permissions' => [
                    [
                        'key' => MerchantPermission::PosStaffView->value,
                        'label_en' => 'See the staff roster',
                        'label_ar' => 'عرض قائمة الموظفين',
                    ],
                    [
                        'key' => MerchantPermission::PosStaffCreate->value,
                        'label_en' => 'Hire new staff',
                        'label_ar' => 'توظيف موظفين جدد',
                    ],
                    [
                        'key' => MerchantPermission::PosStaffUpdate->value,
                        'label_en' => 'Edit staff + reset PIN',
                        'label_ar' => 'تعديل بيانات الموظفين وإعادة تعيين الرمز',
                    ],
                    [
                        'key' => MerchantPermission::PosStaffRevoke->value,
                        'label_en' => 'Suspend + terminate',
                        'label_ar' => 'إيقاف وإنهاء التوظيف',
                    ],
                ],
            ],
            [
                'key' => 'branches',
                'label_en' => 'Branches',
                'label_ar' => 'الفروع',
                'permissions' => [
                    [
                        'key' => MerchantPermission::BranchesView->value,
                        'label_en' => 'See the branches list',
                        'label_ar' => 'عرض قائمة الفروع',
                    ],
                    [
                        'key' => MerchantPermission::BranchesUpdate->value,
                        'label_en' => 'Edit details + hours + contact',
                        'label_ar' => 'تعديل التفاصيل والساعات وبيانات التواصل',
                    ],
                    [
                        'key' => MerchantPermission::BranchesTransitionStatus->value,
                        'label_en' => 'Activate or deactivate a branch',
                        'label_ar' => 'تفعيل أو إيقاف فرع',
                    ],
                ],
            ],
            [
                'key' => 'floor_plan',
                'label_en' => 'Floor Plan',
                'label_ar' => 'مخطط القاعة',
                'permissions' => [
                    [
                        'key' => MerchantPermission::FloorPlanView->value,
                        'label_en' => 'See floors + tables',
                        'label_ar' => 'عرض القاعات والطاولات',
                    ],
                    [
                        'key' => MerchantPermission::FloorPlanManage->value,
                        'label_en' => 'Create + edit + delete floors and tables',
                        'label_ar' => 'إنشاء وتعديل وحذف القاعات والطاولات',
                    ],
                ],
            ],
            [
                'key' => 'catalogue',
                'label_en' => 'Catalogue (Menu)',
                'label_ar' => 'الكتالوج (القائمة)',
                'permissions' => [
                    [
                        'key' => MerchantPermission::CatalogueView->value,
                        'label_en' => 'See categories + products + add-ons',
                        'label_ar' => 'عرض الفئات والمنتجات والإضافات',
                    ],
                    [
                        'key' => MerchantPermission::CatalogueManage->value,
                        'label_en' => 'Create + edit + delete catalogue items',
                        'label_ar' => 'إنشاء وتعديل وحذف عناصر الكتالوج',
                    ],
                ],
            ],
            [
                'key' => 'inventory',
                'label_en' => 'Inventory',
                'label_ar' => 'المخزون',
                'permissions' => [
                    [
                        'key' => MerchantPermission::InventoryView->value,
                        'label_en' => 'See ingredients + suppliers + branch stock + movements',
                        'label_ar' => 'عرض المكونات والموردين ومخزون الفروع والحركات',
                    ],
                    [
                        'key' => MerchantPermission::InventoryManage->value,
                        'label_en' => 'Create + edit + delete inventory items, adjust + restock branch stock, record waste',
                        'label_ar' => 'إنشاء وتعديل وحذف عناصر المخزون وتعديل وتجديد رصيد الفروع وتسجيل الهدر',
                    ],
                    // Phase 5c — restock-request workflow keys.
                    // Kept in the Inventory domain block so the
                    // role-builder UI groups them with the rest
                    // of the stock controls.
                    [
                        'key' => MerchantPermission::RestockRequestCreate->value,
                        'label_en' => 'Submit a restock request from a branch',
                        'label_ar' => 'إرسال طلب تجديد المخزون من الفرع',
                    ],
                    [
                        'key' => MerchantPermission::RestockRequestReview->value,
                        'label_en' => 'Approve, reject, cancel, or allocate a restock request',
                        'label_ar' => 'الموافقة على طلبات التجديد أو رفضها أو إلغاؤها أو تخصيصها',
                    ],
                ],
            ],
            [
                // Phase 6a — customer book. Lives between
                // catalogue (what you sell) and inventory (what
                // you sell it with) in the role-builder UI.
                'key' => 'customers',
                'label_en' => 'Customers',
                'label_ar' => 'العملاء',
                'permissions' => [
                    [
                        'key' => MerchantPermission::CustomersView->value,
                        'label_en' => 'See the customer book + vehicle plates',
                        'label_ar' => 'عرض دفتر العملاء وأرقام المركبات',
                    ],
                    [
                        'key' => MerchantPermission::CustomersManage->value,
                        'label_en' => 'Create + edit + delete customers and their vehicle plates',
                        'label_ar' => 'إنشاء وتعديل وحذف العملاء وأرقام المركبات',
                    ],
                ],
            ],
            [
                'key' => 'roles',
                'label_en' => 'Roles & Permissions',
                'label_ar' => 'الأدوار والصلاحيات',
                'permissions' => [
                    [
                        'key' => MerchantPermission::RolesView->value,
                        'label_en' => 'See the roles list',
                        'label_ar' => 'عرض قائمة الأدوار',
                    ],
                    [
                        'key' => MerchantPermission::RolesManage->value,
                        'label_en' => 'Create + edit + delete roles + assign to users',
                        'label_ar' => 'إنشاء وتعديل وحذف الأدوار وتعيينها للمستخدمين',
                    ],
                ],
            ],
        ];
    }

    /**
     * Flat list of all permission keys — the union of every
     * permissions array above. Used by SeedMerchantRolesAction
     * to assign the full set to SuperAdmin (instead of having
     * to maintain a separate hand-rolled list).
     *
     * @return list<string>
     */
    public static function allMerchantKeys(): array
    {
        $out = [];
        foreach (self::merchant() as $group) {
            foreach ($group['permissions'] as $perm) {
                $out[] = $perm['key'];
            }
        }
        return $out;
    }
}
