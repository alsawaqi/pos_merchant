<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Mail\PasswordResetMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Mail\MailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // Review finding: a link is only worth minting when real mail can
        // deliver it — with MAIL_MAILER=log (or SMTP without a host) it
        // would only be written into a log file. Nothing is minted, sent
        // or logged; the endpoint still answers 200.
        if (! MailDelivery::configured()) {
            Log::info('Forgot-password link not sent: no mail transport is configured (MAIL_MAILER).', [
                'user_id' => $user->id,
            ]);

            return;
        }

        $recentlyMinted = PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'forgot')
            ->where('created_at', '>=', now()->subSeconds(self::MINT_COOLDOWN_SECONDS))
            ->exists();

        if ($recentlyMinted) {
            // The previous link is still fresh in their inbox.
            return;
        }

        $rawToken = Str::random(64);
        $expiresAt = now()->addMinutes(self::EXPIRY_MINUTES);

        DB::transaction(function () use ($user, $rawToken, $expiresAt): void {
            // Invalidate older FORGOT links only — the newest one in
            // the mailbox works. A link issued by an admin or by the
            // team's owner (invite / reset) stays valid: anyone can
            // type an email here, so this must never revoke it
            // (review finding).
            PasswordResetToken::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->where('purpose', 'forgot')
                ->delete();

            PasswordResetToken::query()->create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $rawToken),
                // LAUNCH-P1 P1-2: the shared table also holds the
                // admin-issued invite / reset links.
                'purpose' => 'forgot',
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
            // Synchronous, so a failure is reported at once.
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
