<?php

declare(strict_types=1);

namespace App\Actions\Pos\Role;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Delete a custom merchant role.
 *
 * Refuses:
 *   - system roles (the seeder would re-create them and the
 *     UI hides the delete button anyway; this guard is
 *     defence-in-depth against a hand-crafted DELETE).
 *   - roles still assigned to any user (deletion would silently
 *     strip that user's permissions; the merchant must
 *     re-assign first). Returns 422 with a clear error.
 *
 * Audit event: role.deleted.
 */
final readonly class DeleteRoleAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Role $role, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $role->team_id !== $companyId) {
            abort(404);
        }

        if ($role->is_system) {
            throw new RuntimeException(
                'System roles cannot be deleted. You can edit which permissions they hold instead.',
            );
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);

        try {
            DB::transaction(function () use ($role, $actor, $companyId): void {
                $userCount = $role->users()->count();
                if ($userCount > 0) {
                    throw new RuntimeException(
                        sprintf(
                            'This role is assigned to %d user(s). Re-assign them to another role first, then delete.',
                            $userCount,
                        ),
                    );
                }

                $snapshot = [
                    'name' => $role->name,
                    'description' => $role->description,
                    'permissions' => $role->permissions()->pluck('name')->all(),
                ];

                $roleId = $role->id;
                $role->delete();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'role.deleted',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Role::class,
                    auditableId: $roleId,
                    oldValues: $snapshot,
                ));
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
