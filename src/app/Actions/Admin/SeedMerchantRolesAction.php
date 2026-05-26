<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ensures the 5 default merchant roles + permission catalogue
 * exist under a given company's spatie team scope.
 *
 * Idempotent — safe to call on every CreateMerchantUserAction
 * invocation. Each role is firstOrCreate'd, then synced to its
 * permission set. Existing custom permissions added to a role
 * via a future role-builder UI are preserved because syncPermissions
 * only touches the rows we know about (no, wait — syncPermissions
 * IS destructive: it replaces the pivot. So if a custom permission
 * has been granted, this would wipe it. Use Role::give Permission
 * To if we want a non-destructive merge. For v1, we re-sync on
 * every call because there are no custom permissions yet. Revisit
 * when role-builder lands.)
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

            foreach ($this->roleMatrix() as $roleName => $permissions) {
                /** @var Role $role */
                $role = Role::query()->firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'team_id' => $companyId,
                ]);

                $role->syncPermissions($permissions);
            }

            $registrar->forgetCachedPermissions();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }

    /**
     * Phase 4.5 matrix. SuperAdmin gets every permission; the
     * other roles get progressively narrower slices. New
     * permission keys land here too as future phases add them.
     *
     * @return array<string, list<string>>
     */
    private function roleMatrix(): array
    {
        $all = MerchantPermission::values();

        return [
            // Owner-tier — every permission, including future ones
            // added to the enum.
            MerchantRole::SuperAdmin->value => $all,

            // Managers can see + invite portal users + update
            // them but cannot revoke (suspend/delete) — that's
            // a more sensitive action reserved for SuperAdmin.
            MerchantRole::Manager->value => [
                MerchantPermission::PortalUsersView->value,
                MerchantPermission::PortalUsersInvite->value,
                MerchantPermission::PortalUsersUpdate->value,
            ],

            // Inventory / cashier-supervisor / viewer: no portal-
            // user privileges in Phase 4.5. They will pick up
            // domain-specific permissions in subsequent phases
            // (inventory.*, products.*, reports.*) — listed here
            // as empty so the role still exists in the catalogue.
            MerchantRole::InventoryManager->value => [],
            MerchantRole::CashierSupervisor->value => [],
            MerchantRole::Viewer->value => [],
        ];
    }
}
