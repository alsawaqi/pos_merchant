<?php

declare(strict_types=1);

namespace App\Actions\Pos\Role;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create a custom merchant role.
 *
 * Custom roles are user-managed (deletable, renameable, fully
 * editable). The 5 system roles seeded by
 * {@see \App\Actions\Admin\SeedMerchantRolesAction} can be
 * edited but never deleted; this action only handles the
 * user-created ones.
 *
 * Permission whitelist: every key the caller asks for MUST be
 * in the MerchantPermission enum. Unknown keys are dropped
 * silently to defend against a client bug submitting permissions
 * from a future enum (or a typo) that would otherwise pollute
 * the role's permission set with orphan strings.
 *
 * The new role lives under the actor's company team scope
 * (team_id=company_id). Unique constraint (team_id, name,
 * guard_name) prevents a merchant from creating two roles with
 * the same name.
 *
 * Audit event: role.created.
 */
final readonly class CreateRoleAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, permissions?: list<string>}  $attributes
     */
    public function handle(array $attributes, User $actor): Role
    {
        $companyId = $this->tenant->requiredId();
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);

        try {
            return DB::transaction(function () use ($attributes, $actor, $companyId): Role {
                // Filter to known permission keys only.
                $requested = $attributes['permissions'] ?? [];
                $allowed = array_values(array_intersect(
                    $requested,
                    MerchantPermission::values(),
                ));

                /** @var Role $role */
                $role = Role::query()->create([
                    'name' => $attributes['name'],
                    'guard_name' => 'web',
                    'team_id' => $companyId,
                    'is_system' => false,
                    'description' => $attributes['description'] ?? null,
                ]);

                if ($allowed !== []) {
                    $role->syncPermissions($allowed);
                }

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'role.created',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Role::class,
                    auditableId: $role->id,
                    newValues: [
                        'name' => $role->name,
                        'description' => $role->description,
                        'permissions' => $allowed,
                    ],
                ));

                return $role;
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
