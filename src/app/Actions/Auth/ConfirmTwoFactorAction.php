<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\Auth\TwoFactorAuth;
use Illuminate\Validation\ValidationException;

/**
 * Finish TOTP enrolment (Phase D8): the user proves possession of
 * the authenticator by submitting a valid current code, which flips
 * two_factor_confirmed_at and — from the very next login — gates
 * the session behind the challenge step.
 *
 * Returns the one-time recovery codes in PLAINTEXT exactly once;
 * only their SHA-256 hashes persist (inside the encrypted JSON
 * column), so neither a DB dump nor a later API call can re-read
 * them.
 */
final readonly class ConfirmTwoFactorAction
{
    public function __construct(
        private TwoFactorAuth $twoFactor,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @return list<string> the plaintext recovery codes (shown once)
     *
     * @throws ValidationException
     */
    public function handle(User $user, string $code): array
    {
        if ($user->hasConfirmedTwoFactor()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('Two-factor authentication is already enabled.')],
            ]);
        }

        $secret = (string) $user->two_factor_secret;

        if ($secret === '') {
            throw ValidationException::withMessages([
                'two_factor' => [__('Start two-factor setup before confirming a code.')],
            ]);
        }

        if (! $this->twoFactor->verifyCode($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two-factor code is invalid.')],
            ]);
        }

        ['plain' => $plain, 'hashed' => $hashed] = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $hashed, // encrypted:array cast
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'portal_user.two_factor_enabled',
            actorUserId: (int) $user->id,
            companyId: $user->company_id === null ? null : (int) $user->company_id,
            auditableType: User::class,
            auditableId: (int) $user->id,
            newValues: [
                'enabled_at' => now()->toIso8601String(),
                'recovery_codes_issued' => count($plain),
            ],
        ));

        return $plain;
    }
}
