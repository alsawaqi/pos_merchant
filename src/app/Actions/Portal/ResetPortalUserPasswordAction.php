<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Actions\Auth\IssueSetPasswordLinkAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Reset a teammate's password from inside the merchant portal — owner
 * follow-up 2026-10-01 (same rule as an admin reset in pos_admin):
 *
 *  - no password is generated or shown;
 *  - the teammate's OLD PASSWORD STOPS WORKING AT ONCE (set to NULL) and
 *    every session of theirs ends (auth_version + remember token);
 *  - a single-use 60-minute reset link is issued: copied by the owner
 *    and emailed when mail is configured. Signing in with the old
 *    password is answered with "use the set-password link".
 *
 * A teammate without a password (never set one, or already reset) gets a
 * fresh link of the same kind (invite 72 h / reset 60 min).
 *
 * Audit: `portal_user.password_reset` + `portal_user.set_password_link_issued`.
 * Password and token material are never logged.
 */
final readonly class ResetPortalUserPasswordAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private IssueSetPasswordLinkAction $issueLink,
    ) {}

    /**
     * @return array{user: User, link: SetPasswordLink}
     */
    public function handle(User $user, User $actor): array
    {
        $companyId = $this->tenant->requiredId();

        if ($user->company_id !== $companyId) {
            abort(404);
        }

        app(AuthorizePortalUserManagement::class)->handle($actor, $user);

        $hadPassword = $user->password !== null;

        if ($hadPassword) {
            DB::transaction(function () use ($user, $actor, $companyId): void {
                $user->forceFill([
                    'password' => null,
                    'remember_token' => null,
                    'auth_version' => random_int(1, 9007199254740991),
                ])->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'portal_user.password_reset',
                    actorUserId: (int) $actor->getKey(),
                    companyId: $companyId,
                    auditableType: User::class,
                    auditableId: (int) $user->id,
                    newValues: [
                        'reset_at' => now()->toIso8601String(),
                        'reset_by_side' => 'merchant_portal',
                        'method' => 'set_password_link',
                        'old_password_blocked' => true,
                        'sessions_ended' => true,
                    ],
                ));
            });
        }

        $link = $this->issueLink->handle(
            $user,
            $hadPassword ? 'reset' : IssueSetPasswordLinkAction::purposeForUserWithoutPassword($user),
            $actor,
        );

        return ['user' => $user->refresh(), 'link' => $link];
    }
}
