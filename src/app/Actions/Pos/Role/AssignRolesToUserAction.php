<?php

declare(strict_types=1);

namespace App\Actions\Pos\Role;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantRole;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Replace a user's role list with the requested set.
 *
 * Roles are addressed by name (the value strings from the
 * MerchantRole enum + any custom role's display name). Names
 * are validated against existing roles under the actor's
 * company team scope — passing the SuperAdmin name of another
 * company has no effect because the lookup is team-scoped.
 *
 * Self-rescue guarantee: the actor cannot strip the SuperAdmin
 * role from themselves. Otherwise an absent-minded owner could
 * downgrade themselves to Viewer and leave the company with
 * nobody able to fix it. (Other roles CAN be self-removed — a
 * Manager who picks up an extra role can drop it themselves.)
 *
 * Audit event: portal_user.roles_changed with old + new arrays.
 */
final readonly class AssignRolesToUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  list<string>  $roleNames
     */
    public function handle(User $portalUser, array $roleNames, User $actor): User
    {
        $companyId = $this->tenant->requiredId();

        // Cross-tenant: refuse to mutate a user from another
        // company.
        if ((int) $portalUser->company_id !== $companyId) {
            abort(404);
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);

        try {
            return DB::transaction(function () use ($portalUser, $roleNames, $actor, $companyId): User {
                // Resolve names → role rows in this team scope.
                // Unknown names silently dropped (matches the
                // permission-whitelist pattern in CreateRoleAction)
                // — surfacing them as validation errors is the
                // FormRequest's job.
                $resolvedRoles = Role::query()
                    ->where('guard_name', 'web')
                    ->where('team_id', $companyId)
                    ->whereIn('name', $roleNames)
                    ->get();
                $resolvedNames = $resolvedRoles->pluck('name')->sort()->values()->all();

                $oldNames = $portalUser->getRoleNames()->sort()->values()->all();

                // Self-rescue: actor cannot remove SuperAdmin
                // from themselves.
                if (
                    $portalUser->getKey() === $actor->getKey()
                    && in_array(MerchantRole::SuperAdmin->value, $oldNames, true)
                    && ! in_array(MerchantRole::SuperAdmin->value, $resolvedNames, true)
                ) {
                    throw new RuntimeException(
                        'You cannot remove your own Super Admin role. Ask another Super Admin to do it for you.',
                    );
                }

                if ($oldNames === $resolvedNames) {
                    // No-op — caller submitted the same role set
                    // we already have.
                    return $portalUser->fresh();
                }

                $portalUser->syncRoles($resolvedRoles);

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'portal_user.roles_changed',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: User::class,
                    auditableId: $portalUser->id,
                    oldValues: ['roles' => $oldNames],
                    newValues: ['roles' => $resolvedNames],
                ));

                return $portalUser->fresh();
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
