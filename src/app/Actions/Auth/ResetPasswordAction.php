<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Consume a forgot-password token and set the user's new password
 * (Phase D7).
 *
 * Every failure mode (unknown email, non-merchant row, wrong token,
 * expired token, already-used token) collapses into the SAME
 * generic validation message so the public endpoint never reveals
 * which part was wrong.
 *
 * On success the user's must_change_password flag clears too — the
 * whole point of the forced-first-login flag is "prove you chose
 * your own secret", which a completed reset satisfies. Other
 * sessions are NOT revoked, matching the existing change-password
 * flow's behaviour.
 */
final readonly class ResetPasswordAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $email, string $rawToken, string $password): User
    {
        $user = User::query()
            ->merchant()
            ->where('email', Str::lower($email))
            ->where('status', 'active')
            ->first();

        $token = $user === null ? null : PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $rawToken))
            ->whereNull('used_at')
            ->first();

        if ($user === null || $token === null || $token->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'token' => [__('passwords.token')],
            ]);
        }

        DB::transaction(function () use ($user, $token, $password): void {
            $user->forceFill([
                'password' => $password, // hashed by the model cast
                'must_change_password' => false,
            ])->save();

            $token->forceFill(['used_at' => now()])->save();

            // Defence in depth — any other outstanding token for this
            // user dies with the reset (SendPasswordResetLinkAction
            // already keeps at most one alive).
            PasswordResetToken::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.password_reset_completed',
                actorUserId: (int) $user->id,
                companyId: $user->company_id === null ? null : (int) $user->company_id,
                auditableType: User::class,
                auditableId: (int) $user->id,
                newValues: [
                    'reset_at' => now()->toIso8601String(),
                    'reset_via' => 'forgot_password_link',
                ],
            ));
        });

        return $user;
    }
}
