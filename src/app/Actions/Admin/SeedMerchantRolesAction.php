<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ensures the 5 default merchant roles + permission catalogue
 * exist under a given company's spatie team scope.
 *
 * Idempotent — safe to call on every CreateMerchantUserAction
 * invocation.
 *
 * Phase 4.8 changes vs the original 4.5 version:
 *
 *   - Each default role is stamped `is_system=true` so the
 *     role-builder UI hides the delete button + locks rename.
 *     A merchant SuperAdmin can still mutate which permissions
 *     a system role holds (so they can tighten or loosen
 *     "Manager" to taste), but the canonical name + the row's
 *     existence are guaranteed.
 *
 *   - We do NOT call syncPermissions on every run any more —
 *     that would wipe a user's custom edits. Instead, on first
 *     creation the role gets its seeded permission set; on
 *     subsequent runs only the row's metadata (is_system,
 *     description) is refreshed. If a new permission key lands
 *     in a later phase and a user wants their system "Manager"
 *     role to pick it up, they edit it in the UI. The role
 *     never silently drifts back to the seeder's idea of what
 *     it should hold.
 *
 *   - SuperAdmin is a special case: it always gets the FULL
 *     permission set on every run. This protects against the
 *     "merchant accidentally removed a permission from the
 *     owner role and now nobody can fix it" footgun.
 *
 * The PermissionRegistrar team_id switch is critical: spatie's
 * pivot tables include team_id, so without setting it correctly,
 * the role rows land under team_id=0 (the pos_admin platform
 * team) and the merchant's roles become invisible.
 */
final class SeedMerchantRolesAction
{
    public function handle(int $companyId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);

        try {
            // Permissions are NOT team-scoped in spatie (they're
            // global names), but firstOrCreate is still safe to
            // call repeatedly — it only inserts when missing.
            foreach (MerchantPermission::values() as $permissionName) {
                Permission::query()->firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
            }

            foreach ($this->roleCatalogue() as $roleName => $meta) {
                $existing = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->where('team_id', $companyId)
                    ->first();

                if ($existing === null) {
                    // First-time seed — create + assign the
                    // initial permission set.
                    /** @var Role $role */
                    $role = Role::query()->create([
                        'name' => $roleName,
                        'guard_name' => 'web',
                        'team_id' => $companyId,
                        'is_system' => true,
                        'description' => $meta['description'],
                    ]);
                    $role->syncPermissions($meta['permissions']);

                    continue;
                }

                // Existing row — refresh metadata via a direct
                // DB update (avoids triggering observer hooks)
                // but DO NOT touch the permission pivot. Custom
                // edits stay intact.
                DB::table('pos_roles')
                    ->where('id', $existing->id)
                    ->update([
                        'is_system' => true,
                        'description' => $meta['description'],
                    ]);

                // SuperAdmin gets force-resynced to the full
                // permission set on every run — guarantees the
                // owner can never lock themselves out by
                // editing this role.
                if ($roleName === MerchantRole::SuperAdmin->value) {
                    $existing->syncPermissions(MerchantPermission::values());
                }
            }

            $registrar->forgetCachedPermissions();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }

    /**
     * The 5 seeded defaults. Each has a description shown in
     * the UI + an initial permission set used ONLY on first
     * creation (subsequent runs preserve user edits, except
     * for SuperAdmin which is always force-resynced).
     *
     * @return array<string, array{description: string, permissions: list<string>}>
     */
    private function roleCatalogue(): array
    {
        return [
            MerchantRole::SuperAdmin->value => [
                'description' => 'Full access to every feature. Cannot be deleted.',
                // Computed at call time so any future permission
                // additions to the enum land here automatically.
                'permissions' => MerchantPermission::values(),
            ],

            MerchantRole::Manager->value => [
                'description' => 'Day-to-day operations — hire staff, edit branches, manage portal teammates + floor plan + catalogue. Cannot deactivate branches or manage roles.',
                'permissions' => [
                    MerchantPermission::PortalUsersView->value,
                    MerchantPermission::PortalUsersInvite->value,
                    MerchantPermission::PortalUsersUpdate->value,
                    MerchantPermission::PosStaffView->value,
                    MerchantPermission::PosStaffCreate->value,
                    MerchantPermission::PosStaffUpdate->value,
                    MerchantPermission::PosStaffRevoke->value,
                    MerchantPermission::BranchesView->value,
                    MerchantPermission::BranchesUpdate->value,
                    MerchantPermission::RolesView->value,
                    MerchantPermission::FloorPlanView->value,
                    MerchantPermission::FloorPlanManage->value,
                    // Catalogue: full CRUD — managers edit the
                    // menu regularly (seasonal items, pricing
                    // changes, new SKUs).
                    MerchantPermission::CatalogueView->value,
                    MerchantPermission::CatalogueManage->value,
                    // Inventory (Phase 5a): managers run the
                    // day-to-day buying, adjusting, and waste
                    // reporting.
                    MerchantPermission::InventoryView->value,
                    MerchantPermission::InventoryManage->value,
                    // Restock workflow (Phase 5c): managers
                    // sit on both sides — they can both submit
                    // a request when they're at a branch AND
                    // review one when they're at HQ.
                    MerchantPermission::RestockRequestCreate->value,
                    MerchantPermission::RestockRequestReview->value,
                    // Phase 6a: managers own the customer book.
                    MerchantPermission::CustomersView->value,
                    MerchantPermission::CustomersManage->value,
                    // Phase 6b: managers configure loyalty rates
                    // + grant manual point/wallet adjustments.
                    MerchantPermission::LoyaltyView->value,
                    MerchantPermission::LoyaltyManage->value,
                    // Phase 6d: managers configure discount rules.
                    MerchantPermission::DiscountsView->value,
                    MerchantPermission::DiscountsManage->value,
                    // Phase 6 backfill: managers own the expense
                    // review queue (approve / reject / annotate).
                    MerchantPermission::ExpensesView->value,
                    MerchantPermission::ExpensesManage->value,
                    // Phase 7b: managers run the daily review --
                    // reports + exports + audit log.
                    MerchantPermission::ReportsView->value,
                    MerchantPermission::ReportsExport->value,
                    MerchantPermission::AuditLogView->value,
                ],
            ],

            MerchantRole::CashierSupervisor->value => [
                'description' => 'Shift supervisor — view staff + branch list + floor plan + menu + inventory + customers. Cannot hire / fire / reset PINs, change the menu, or adjust stock.',
                'permissions' => [
                    MerchantPermission::PosStaffView->value,
                    MerchantPermission::PosStaffUpdate->value,
                    MerchantPermission::BranchesView->value,
                    MerchantPermission::RolesView->value,
                    MerchantPermission::FloorPlanView->value,
                    MerchantPermission::CatalogueView->value,
                    // Inventory read-only: useful for spotting
                    // low-stock items mid-shift without authority
                    // to restock.
                    MerchantPermission::InventoryView->value,
                    // Phase 6a: supervisors look up customers
                    // during reporting / lookup. No write — the
                    // POS terminal handles in-flight create on
                    // its own (Phase 7+).
                    MerchantPermission::CustomersView->value,
                    // Phase 6b: supervisors see balances during
                    // a shift but don't move money around.
                    MerchantPermission::LoyaltyView->value,
                    // Phase 6d: supervisors see discount rules
                    // to understand what the POS auto-applied
                    // mid-shift.
                    MerchantPermission::DiscountsView->value,
                    // Phase 6 backfill: supervisors see expenses
                    // logged during their shift but don't review
                    // them (that's a Manager call).
                    MerchantPermission::ExpensesView->value,
                    // Phase 7b: supervisors see today's sales
                    // numbers + recent activity. No export, no
                    // audit log -- those are Manager+ tools.
                    MerchantPermission::ReportsView->value,
                ],
            ],

            MerchantRole::Viewer->value => [
                'description' => 'Read-only — see staff, branch list, floor plan, menu, inventory, customers, loyalty balances, discount rules, and reports. No write access.',
                'permissions' => [
                    MerchantPermission::PosStaffView->value,
                    MerchantPermission::BranchesView->value,
                    MerchantPermission::RolesView->value,
                    MerchantPermission::FloorPlanView->value,
                    MerchantPermission::CatalogueView->value,
                    MerchantPermission::InventoryView->value,
                    MerchantPermission::CustomersView->value,
                    MerchantPermission::LoyaltyView->value,
                    MerchantPermission::DiscountsView->value,
                    // Phase 6 backfill: read-only expense visibility.
                    MerchantPermission::ExpensesView->value,
                    // Phase 7b: viewers see reports but can't
                    // export or read the audit log.
                    MerchantPermission::ReportsView->value,
                ],
            ],

            MerchantRole::InventoryManager->value => [
                'description' => 'Inventory specialist — owns the menu catalogue (Phase 6) AND ingredients + stock (Phase 5a). The role finally lives up to its name.',
                'permissions' => [
                    MerchantPermission::BranchesView->value,
                    MerchantPermission::RolesView->value,
                    // Catalogue + Inventory are THE
                    // inventory-manager surfaces.
                    MerchantPermission::CatalogueView->value,
                    MerchantPermission::CatalogueManage->value,
                    MerchantPermission::InventoryView->value,
                    MerchantPermission::InventoryManage->value,
                    // Restock workflow (Phase 5c): the
                    // inventory specialist owns both sides of
                    // the request flow.
                    MerchantPermission::RestockRequestCreate->value,
                    MerchantPermission::RestockRequestReview->value,
                    // Phase 6a: inventory specialist sees but
                    // doesn't manage customers — the customer
                    // book is a Manager concern, not an inventory
                    // one. They get View so they can correlate a
                    // request with the customer who triggered it.
                    MerchantPermission::CustomersView->value,
                    // Phase 6b: same logic — see balances for
                    // context, but don't adjust them.
                    MerchantPermission::LoyaltyView->value,
                    // Phase 6d: inventory specialist often
                    // configures the promo calendar alongside
                    // ingredient costs, so both view + manage.
                    MerchantPermission::DiscountsView->value,
                    MerchantPermission::DiscountsManage->value,
                    // Phase 6 backfill: supplier cash payments are
                    // inventory-adjacent, so the specialist reviews
                    // expenses too.
                    MerchantPermission::ExpensesView->value,
                    MerchantPermission::ExpensesManage->value,
                    // Phase 7b: inventory specialist runs the
                    // inventory-side reports (Loss/Waste,
                    // Consumption, Restock/Purchasing,
                    // Recipe & Cost). Gets exports + audit log
                    // because they own the operational review.
                    MerchantPermission::ReportsView->value,
                    MerchantPermission::ReportsExport->value,
                    MerchantPermission::AuditLogView->value,
                ],
            ],
        ];
    }
}
