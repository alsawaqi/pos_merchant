<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Partial-update name / phone / role / branch_scope on a teammate.
 *
 * Refuses cross-tenant writes via the tenant context check, even
 * though the controller already guards. Defence in depth: an
 * action called from a job or a future internal caller still
 * can't escape the company scope.
 *
 * Email is intentionally NOT updatable here — it's the login
 * identity + the audit-log stable handle. Renames are rare
 * enough that delete-and-recreate is the right answer.
 *
 * Audit event: `portal_user.updated` with old + new values for
 * the diff drawer in pos_admin's audit log viewer.
 */
final readonly class UpdatePortalUserAction
{
    public function __construct(
        private SeedMerchantRolesAction $seedRoles,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name?: string, phone?: string|null, role?: string, branch_scope?: array<int>|null}  $attributes
     */
    public function handle(User $user, array $attributes, User $actor): User
    {
        $companyId = $this->tenant->requiredId();

        if ($user->company_id !== $companyId) {
            // Cross-tenant write attempt. Controller should have
            // caught this already; we throw 404-equivalent
            // semantics from here too.
            abort(404);
        }

        return DB::transaction(function () use ($user, $attributes, $actor, $companyId): User {
            $registrar = app(PermissionRegistrar::class);
            $previousTeam = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($companyId);

            try {
                $beforeRole = $user->getRoleNames()->first();

                $changes = [];
                if (array_key_exists('name', $attributes) && $attributes['name'] !== $user->name) {
                    $changes['name'] = ['old' => $user->name, 'new' => $attributes['name']];
                    $user->name = $attributes['name'];
                }
                if (array_key_exists('phone', $attributes) && $attributes['phone'] !== $user->phone) {
                    $changes['phone'] = ['old' => $user->phone, 'new' => $attributes['phone']];
                    $user->phone = $attributes['phone'];
                }
                if (array_key_exists('branch_scope', $attributes)) {
                    $newScope = $attributes['branch_scope'];
                    if (json_encode($newScope) !== json_encode($user->branch_scope_json)) {
                        $changes['branch_scope'] = [
                            'old' => $user->branch_scope_json,
                            'new' => $newScope,
                        ];
                        $user->branch_scope_json = $newScope;
                    }
                }
                $user->save();

                if (array_key_exists('role', $attributes) && $attributes['role'] !== $beforeRole) {
                    // Seed roles lazily before assignment in case
                    // the merchant is the FIRST to use this role
                    // (pos_admin only seeded SuperAdmin).
                    $this->seedRoles->handle($companyId);

                    $role = Role::query()
                        ->where('name', $attributes['role'])
                        ->where('team_id', $companyId)
                        ->firstOrFail();
                    $user->syncRoles([$role]);
                    $changes['role'] = ['old' => $beforeRole, 'new' => $attributes['role']];
                }

                if ($changes !== []) {
                    $this->writeAuditLog->handle(new AuditLogData(
                        event: 'portal_user.updated',
                        actorUserId: $actor->getKey(),
                        companyId: $companyId,
                        auditableType: User::class,
                        auditableId: $user->id,
                        oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                        newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
                    ));
                }

                return $user->fresh();
            } finally {
                $registrar->setPermissionsTeamId($previousTeam);
            }
        });
    }
}
