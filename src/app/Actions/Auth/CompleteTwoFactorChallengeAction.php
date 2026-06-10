<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\Auth\TwoFactorAuth;

/**
 * Verify the second factor during the login challenge (Phase D8).
 *
 * Accepts EITHER a live TOTP code (±1 period drift window) OR one
 * unused recovery code. A matched recovery code is burnt
 * immediately (removed from the stored hash list) so it can never
 * be replayed.
 *
 * Every outcome is audited — success and failure — because a
 * failed challenge means someone holds the account's PASSWORD and
 * is guessing codes; that's a signal the audit trail must keep.
 */
final readonly class CompleteTwoFactorChallengeAction
{
    public function __construct(
        private TwoFactorAuth $twoFactor,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(User $user, ?string $code, ?string $recoveryCode): bool
    {
        $method = null;

        if ($code !== null && $code !== '' && $this->twoFactor->verifyCode((string) $user->two_factor_secret, $code)) {
            $method = 'totp';
        } elseif ($recoveryCode !== null && $recoveryCode !== '') {
            /** @var list<string> $hashedCodes */
            $hashedCodes = $user->two_factor_recovery_codes ?? [];
            $index = $this->twoFactor->matchRecoveryCode($hashedCodes, $recoveryCode);

            if ($index !== null) {
                // Burn the matched code — single-use by construction.
                unset($hashedCodes[$index]);
                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($hashedCodes),
                ])->save();

                $method = 'recovery_code';
            }
        }

        $this->writeAuditLog->handle(new AuditLogData(
            event: $method === null
                ? 'portal_user.two_factor_challenge_failed'
                : 'portal_user.two_factor_challenge_passed',
            actorUserId: (int) $user->id,
            companyId: $user->company_id === null ? null : (int) $user->company_id,
            auditableType: User::class,
            auditableId: (int) $user->id,
            newValues: $method === null ? null : [
                'method' => $method,
                'recovery_codes_remaining' => $method === 'recovery_code'
                    ? count($user->two_factor_recovery_codes ?? [])
                    : null,
            ],
        ));

        return $method !== null;
    }
}
