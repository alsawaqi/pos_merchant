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
                'description' => 'Day-to-day operations — hire staff, edit branches, manage portal teammates. Cannot deactivate branches or manage roles.',
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
                ],
            ],

            MerchantRole::CashierSupervisor->value => [
                'description' => 'Shift supervisor — view staff + branch list, edit staff details. Cannot hire / fire / reset PINs.',
                'permissions' => [
                    MerchantPermission::PosStaffView->value,
                    MerchantPermission::PosStaffUpdate->value,
                    MerchantPermission::BranchesView->value,
                    MerchantPermission::RolesView->value,
                ],
            ],

            MerchantRole::Viewer->value => [
                'description' => 'Read-only — see the staff roster and the branch list. No write access.',
                'permissions' => [
                    MerchantPermission::PosStaffView->value,
                    MerchantPermission::BranchesView->value,
                    MerchantPermission::RolesView->value,
                ],
            ],

            MerchantRole::InventoryManager->value => [
                'description' => 'Inventory specialist — branch list + (future) catalogue + stock. No HR or roles.',
                'permissions' => [
                    MerchantPermission::BranchesView->value,
                    MerchantPermission::RolesView->value,
                ],
            ],
        ];
    }
}
