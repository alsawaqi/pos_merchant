<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Mail\PasswordResetMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mint a single-use password-reset token for an ACTIVE merchant
 * portal user and email them the reset link (Phase D7).
 *
 * Anti-enumeration: the action is silent — it returns void whether
 * or not the email matched anything, so the public endpoint can
 * answer 200 unconditionally. Only `user_type='merchant'` rows are
 * considered (the same pre-scoping the login controller does), so
 * a platform-admin account can never be reset through the merchant
 * portal's public endpoint.
 *
 * Token convention mirrors the invite/setup-token flow
 * (pos_admin InvitePortalUserAction): Str::random(64) raw token,
 * SHA-256 hash stored, raw value only ever in the email body.
 *
 * The mail is sent AFTER the DB transaction commits — a transient
 * mailer failure must neither roll back the token row nor 500 the
 * public endpoint (it is reported instead).
 */
final readonly class SendPasswordResetLinkAction
{
    /** Reset links die after one hour. */
    private const EXPIRY_MINUTES = 60;

    /**
     * Don't mint (or mail) more than one token per user per minute,
     * independent of the per-IP endpoint throttle — keeps a botnet
     * spread across IPs from flooding one person's mailbox.
     */
    private const MINT_COOLDOWN_SECONDS = 60;

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(string $email): void
    {
        $user = User::query()
            ->merchant()
            ->where('email', Str::lower($email))
            ->where('status', 'active')
            ->first();

        if ($user === null) {
            // Unknown / non-merchant / non-active email — do nothing.
            // The endpoint still answers 200 so callers learn nothing.
            return;
        }

        $recentlyMinted = PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subSeconds(self::MINT_COOLDOWN_SECONDS))
            ->exists();

        if ($recentlyMinted) {
            // The previous link is still fresh in their inbox.
            return;
        }

        $rawToken = Str::random(64);
        $expiresAt = now()->addMinutes(self::EXPIRY_MINUTES);

        DB::transaction(function () use ($user, $rawToken, $expiresAt): void {
            // Invalidate every outstanding token — only the newest
            // link in the mailbox works, which keeps the "I clicked
            // the old email" support surface small.
            PasswordResetToken::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();

            PasswordResetToken::query()->create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.reset_link_sent',
                companyId: $user->company_id === null ? null : (int) $user->company_id,
                auditableType: User::class,
                auditableId: (int) $user->id,
                metadata: [
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ));
        });

        try {
            // Synchronous in dev (MAIL_MAILER=log → storage/logs).
            Mail::to($user->email)->send(
                new PasswordResetMail($user, $rawToken, $expiresAt),
            );
        } catch (Throwable $e) {
            // A mailer hiccup must not surface on the public
            // endpoint (and the 200 contract holds regardless).
            report($e);
        }
    }
}
