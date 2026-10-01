<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Actions\Auth\IssueSetPasswordLinkAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Merchant-side "create a teammate" — same shape as the pos_admin
 * CreateMerchantUserAction: the login is created WITHOUT a password and
 * a single-use 72-hour set-password link is returned once (copy dialog)
 * and emailed when mail is configured (owner follow-up 2026-10-01: no
 * password generated or shown anywhere). Actor + tenant come from the
 * SIGNED-IN merchant user rather than a platform admin, and the gates
 * are different:
 *
 *   - No "≥1 branch + ≥1 device" check (that gate is for the
 *     FIRST user, which is provisioned by pos_admin; subsequent
 *     teammates can be created without re-checking infrastructure).
 *   - The new user is scoped to the SAME company as the actor
 *     — pulled from MerchantTenantContext, never from the
 *     request body.
 *   - Role is the caller-supplied MerchantRole (any of the 5),
 *     not hardcoded to SuperAdmin.
 *   - branch_scope_json is whatever the caller picked (NULL = all
 *     branches, or an array of branch ids the user is restricted
 *     to).
 *
 * The role catalogue is lazy-seeded via {@see SeedMerchantRolesAction}
 * before assignment so brand-new permission keys land in the
 * pivot even if pos_admin's initial create only seeded
 * `merchant_super_admin`.
 *
 * Audit event: `portal_user.created` (same name pos_admin uses —
 * the row's actor_user_id + company_id make it clear which side
 * created it).
 */
final readonly class CreatePortalUserAction
{
    public function __construct(
        private SeedMerchantRolesAction $seedRoles,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private IssueSetPasswordLinkAction $issueLink,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, role: string, branch_scope?: array<int>|null}  $attributes
     * @return array{user: User, link: SetPasswordLink}
     */
    public function handle(array $attributes, User $actor): array
    {
        $companyId = $this->tenant->requiredId();

        $user = DB::transaction(function () use ($attributes, $actor, $companyId): User {
            // Ensure all 5 default roles + the latest permission
            // catalogue exist under this company's team scope.
            // Idempotent — safe on every call. Cheap because spatie
            // caches the permission set after the first read.
            $this->seedRoles->handle($companyId);
            app(AuthorizePortalUserManagement::class)->handle($actor, roles: [$attributes['role']],
                grantScope: $attributes['branch_scope'] ?? null, changesScope: true);

            /** @var User $user */
            $user = User::query()->create([
                'company_id' => $companyId,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                // Owner follow-up 2026-10-01: no password is generated
                // or shown. The teammate chooses one with the link.
                'password' => null,
                'user_type' => 'merchant',
                'status' => 'active',
                'must_change_password' => false,
                'branch_scope_json' => $attributes['branch_scope'] ?? null,
                'setup_token_hash' => null,
                'setup_token_expires_at' => null,
                'invited_at' => now(),
                // invited_by_admin_id is overloaded: when pos_admin
                // creates the first user it's the platform admin id;
                // when a merchant teammate creates another teammate
                // it's the merchant's own user id. The audit log row
                // tells the two cases apart by user_type lookup.
                'invited_by_admin_id' => $actor->getKey(),
            ]);

            // Assign the chosen role under the company team scope.
            // Switch + restore the registrar's team_id so the rest
            // of the request stays in scope.
            $registrar = app(PermissionRegistrar::class);
            $previousTeam = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($companyId);
            try {
                $role = Role::query()
                    ->where('name', $attributes['role'])
                    ->where('team_id', $companyId)
                    ->firstOrFail();
                $user->assignRole($role);
            } finally {
                $registrar->setPermissionsTeamId($previousTeam);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: User::class,
                auditableId: $user->id,
                newValues: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'role' => $attributes['role'],
                    'branch_scope' => $attributes['branch_scope'] ?? 'all',
                    'created_by_side' => 'merchant_portal',
                    'password' => 'set_password_link',
                ],
            ));

            return $user;
        });

        // After commit: the email must never point at a rolled-back user.
        $link = $this->issueLink->handle($user, 'invite', $actor);

        return ['user' => $user, 'link' => $link];
    }
}
