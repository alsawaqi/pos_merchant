<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\Auth\TwoFactorAuth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Disable TOTP 2FA for the signed-in user (Phase D8).
 *
 * Step-up authorisation: the caller must present BOTH the current
 * password AND a proof-of-second-factor (a live TOTP code or an
 * unused recovery code). A hijacked browser session alone can
 * therefore never strip the account's second factor.
 */
final readonly class DisableTwoFactorAction
{
    public function __construct(
        private TwoFactorAuth $twoFactor,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, string $currentPassword, ?string $code, ?string $recoveryCode): void
    {
        if (! $user->hasConfirmedTwoFactor()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('Two-factor authentication is not enabled.')],
            ]);
        }

        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('auth.password')],
            ]);
        }

        $secret = (string) $user->two_factor_secret;
        /** @var list<string> $hashedCodes */
        $hashedCodes = $user->two_factor_recovery_codes ?? [];

        $secondFactorOk = ($code !== null && $code !== '' && $this->twoFactor->verifyCode($secret, $code))
            || ($recoveryCode !== null && $recoveryCode !== '' && $this->twoFactor->matchRecoveryCode($hashedCodes, $recoveryCode) !== null);

        if (! $secondFactorOk) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two-factor code is invalid.')],
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'portal_user.two_factor_disabled',
            actorUserId: (int) $user->id,
            companyId: $user->company_id === null ? null : (int) $user->company_id,
            auditableType: User::class,
            auditableId: (int) $user->id,
            newValues: [
                'disabled_at' => now()->toIso8601String(),
            ],
        ));
    }
}
