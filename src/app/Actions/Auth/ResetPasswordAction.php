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
 * Consume a password link and set the user's new password (Phase D7;
 * LAUNCH-P1 P1-2).
 *
 * One flow for every link in pos_password_reset_tokens: the merchant's
 * own forgot-password link, the admin-issued set-password invite (72 h,
 * reached via /setup-password) and the admin-issued reset (60 min).
 *
 * Every failure mode (unknown email, non-merchant row, wrong token,
 * expired token, already-used token) collapses into the SAME
 * generic validation message so the public endpoint never reveals
 * which part was wrong.
 *
 * On success the user's must_change_password flag clears too — the
 * whole point of the forced-first-login flag is "prove you chose
 * your own secret", which a completed reset satisfies. The password
 * change rotates auth_version (User::booted), so every other session of
 * the user ends. Audited with the kind of link used.
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
            // Atomic single use (review finding): claim the token with a
            // conditional UPDATE first. Of two requests racing with the
            // same link exactly one changes the row; the other gets the
            // same generic "invalid or expired" answer and changes nothing.
            $claimed = PasswordResetToken::query()
                ->whereKey($token->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now()]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'token' => [__('passwords.token')],
                ]);
            }

            $user->forceFill([
                'password' => $password, // hashed by the model cast
                'must_change_password' => false,
            ])->save();

            // Defence in depth — any other outstanding token for this
            // user dies with the reset (SendPasswordResetLinkAction
            // already keeps at most one alive).
            PasswordResetToken::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();

            // LAUNCH-P1 P1-2: the same page also consumes the admin-issued
            // set-password links (pos_admin writes the purpose).
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.password_reset_completed',
                actorUserId: (int) $user->id,
                companyId: $user->company_id === null ? null : (int) $user->company_id,
                auditableType: User::class,
                auditableId: (int) $user->id,
                newValues: [
                    'reset_at' => now()->toIso8601String(),
                    'reset_via' => match ((string) ($token->purpose ?? 'forgot')) {
                        'invite' => 'set_password_link',
                        'reset' => 'admin_reset_link',
                        default => 'forgot_password_link',
                    },
                    // The password change rotated auth_version: every
                    // other session of this user has ended.
                    'sessions_ended' => true,
                ],
            ));
        });

        return $user;
    }
}
