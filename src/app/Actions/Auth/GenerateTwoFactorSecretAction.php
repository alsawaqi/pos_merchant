<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\Auth\TwoFactorAuth;
use Illuminate\Validation\ValidationException;

/**
 * Start TOTP enrolment for the signed-in user (Phase D8).
 *
 * Mints a fresh secret, stores it encrypted but UNCONFIRMED
 * (two_factor_confirmed_at stays NULL — an unconfirmed secret never
 * gates login), and returns the provisioning payload the SPA shows:
 * the otpauth:// URI as an inline SVG QR plus the manual-entry
 * secret.
 *
 * Calling it again before confirming simply rotates the pending
 * secret (the user re-scans). Once 2FA is CONFIRMED, re-enrolment
 * is refused — disable first (which demands password + code), so a
 * hijacked session can't silently swap the authenticator.
 */
final readonly class GenerateTwoFactorSecretAction
{
    public function __construct(
        private TwoFactorAuth $twoFactor,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @return array{secret: string, otpauth_url: string, svg: string}
     *
     * @throws ValidationException
     */
    public function handle(User $user): array
    {
        if ($user->hasConfirmedTwoFactor()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('Two-factor authentication is already enabled.')],
            ]);
        }

        $secret = $this->twoFactor->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret, // encrypted by the model cast
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'portal_user.two_factor_setup_started',
            actorUserId: (int) $user->id,
            companyId: $user->company_id === null ? null : (int) $user->company_id,
            auditableType: User::class,
            auditableId: (int) $user->id,
        ));

        $issuer = (string) config('pos_merchant_auth.two_factor.issuer', 'MITHQAL Merchant');
        $otpauthUrl = $this->twoFactor->otpauthUrl($issuer, (string) $user->email, $secret);

        return [
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
            'svg' => $this->twoFactor->qrSvg($otpauthUrl),
        ];
    }
}
