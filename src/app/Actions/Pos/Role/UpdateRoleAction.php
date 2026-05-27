<?php

declare(strict_types=1);

namespace App\Actions\Pos\Role;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Edit an existing merchant role.
 *
 *   - name        — only mutable on non-system roles. Renaming
 *                   a system role would break the lookups
 *                   CreateMerchantUserAction does by string
 *                   ("merchant_super_admin" etc).
 *   - description — always mutable.
 *   - permissions — always mutable, but on the SuperAdmin
 *                   system role we refuse to remove RolesManage
 *                   (the owner role must always be able to
 *                   self-rescue) and refuse to remove
 *                   PortalUsersInvite + PosStaffCreate (so the
 *                   owner can never lock themselves out of
 *                   teammate management).
 *
 * Cross-tenant safety: the action re-checks that the role's
 * team_id matches the actor's company, on top of the
 * controller's refuseIfNotInTenant() guard.
 *
 * Audit event: role.updated with old/new pivot diffs.
 */
final readonly class UpdateRoleAction
{
    /**
     * Permissions that must never be removable from the
     * SuperAdmin role (defence against accidental self-lockout).
     * Keep this list narrow — only things that, if removed,
     * make the role unable to UN-remove them.
     *
     * @var list<string>
     */
    private const SUPER_ADMIN_LOCKED_PERMISSIONS = [
        'roles.manage',
        'roles.view',
        'portal_users.view',
        'portal_users.invite',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name?: string, description?: string|null, permissions?: list<string>}  $attributes
     */
    public function handle(Role $role, array $attributes, User $actor): Role
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $role->team_id !== $companyId) {
            abort(404);
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);

        try {
            return DB::transaction(function () use ($role, $attributes, $actor, $companyId): Role {
                $changes = [];

                // ---- name -----------------------------------
                if (array_key_exists('name', $attributes) && $attributes['name'] !== $role->name) {
                    if ($role->is_system) {
                        throw new RuntimeException(
                            'System roles cannot be renamed. Create a new custom role instead.',
                        );
                    }
                    $changes['name'] = ['old' => $role->name, 'new' => $attributes['name']];
                    $role->name = $attributes['name'];
                }

                // ---- description ----------------------------
                if (array_key_exists('description', $attributes) && $attributes['description'] !== $role->description) {
                    $changes['description'] = ['old' => $role->description, 'new' => $attributes['description']];
                    $role->description = $attributes['description'];
                }

                $role->save();

                // ---- permissions ----------------------------
                if (array_key_exists('permissions', $attributes)) {
                    $requested = $attributes['permissions'];
                    $allowed = array_values(array_intersect(
                        $requested,
                        MerchantPermission::values(),
                    ));

                    // SuperAdmin self-rescue guarantee — refuse
                    // to remove any locked permission from this
                    // role specifically.
                    if ($role->name === MerchantRole::SuperAdmin->value) {
                        $missing = array_diff(self::SUPER_ADMIN_LOCKED_PERMISSIONS, $allowed);
                        if ($missing !== []) {
                            throw new RuntimeException(
                                'These permissions cannot be removed from the Super Admin role: ' . implode(', ', $missing),
                            );
                        }
                    }

                    $oldPermissions = $role->permissions()->pluck('name')->sort()->values()->all();
                    $role->syncPermissions($allowed);
                    $newPermissions = $role->permissions()->pluck('name')->sort()->values()->all();

                    if ($oldPermissions !== $newPermissions) {
                        $changes['permissions'] = [
                            'old' => $oldPermissions,
                            'new' => $newPermissions,
                        ];
                    }
                }

                if ($changes !== []) {
                    $this->writeAuditLog->handle(new AuditLogData(
                        event: 'role.updated',
                        actorUserId: $actor->getKey(),
                        companyId: $companyId,
                        auditableType: Role::class,
                        auditableId: $role->id,
                        oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                        newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
                    ));
                }

                return $role->fresh();
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
