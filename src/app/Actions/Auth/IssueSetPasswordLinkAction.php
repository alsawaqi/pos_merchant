<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Mail\SetPasswordLinkMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\Auth\SetPasswordLink;
use App\Support\Mail\MailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Issue a single-use set-password link for a teammate — owner follow-up
 * 2026-10-01: inside the merchant portal too, no password is generated
 * or shown anywhere. Adapted from pos_admin's IssueSetPasswordLinkAction
 * (same token table, same lifetimes, same JSON) — keep both in sync.
 *
 *   invite — a new teammate login: 72 hours (lands on /setup-password)
 *   reset  — a reset by the owner:  60 minutes (lands on /reset-password)
 *
 * Older unused links of the user die first; only the SHA-256 hash is
 * stored. The link is emailed when real mail is configured (a failure
 * is reported, never breaks the request) and returned once for the
 * "Copy set-password link" dialog. The existing ResetPasswordAction
 * consumes it.
 *
 * Call this after the surrounding transaction committed.
 */
final readonly class IssueSetPasswordLinkAction
{
    public const INVITE_TTL_MINUTES = 72 * 60;

    public const RESET_TTL_MINUTES = 60;

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(User $user, string $purpose, ?User $actor = null): SetPasswordLink
    {
        if (! in_array($purpose, ['invite', 'reset'], true)) {
            throw new InvalidArgumentException("Unknown set-password link purpose [{$purpose}].");
        }

        $rawToken = Str::random(64);
        $expiresAt = now()->addMinutes($purpose === 'invite' ? self::INVITE_TTL_MINUTES : self::RESET_TTL_MINUTES);

        DB::transaction(function () use ($user, $purpose, $actor, $rawToken, $expiresAt): void {
            PasswordResetToken::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();

            PasswordResetToken::query()->create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $rawToken),
                'purpose' => $purpose,
                'issued_by_user_id' => $actor?->getKey(),
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);
        });

        $base = rtrim((string) config('app.url'), '/');
        $url = $base.($purpose === 'invite' ? '/setup-password' : '/reset-password').'?'.http_build_query([
            'token' => $rawToken,
            'email' => (string) $user->email,
        ], '', '&', PHP_QUERY_RFC3986);

        $mailConfigured = MailDelivery::configured();
        $emailed = false;
        $emailError = null;

        if ($mailConfigured) {
            try {
                Mail::to((string) $user->email)->send(new SetPasswordLinkMail(
                    recipient: $user,
                    url: $url,
                    expiresAt: $expiresAt,
                    purpose: $purpose,
                    companyName: $user->company?->name,
                ));
                $emailed = true;
            } catch (Throwable $e) {
                report($e);
                $emailError = 'The email could not be sent. Copy the link and send it to the user another way.';
            }
        } else {
            Log::info('Set-password link not emailed: no mail transport is configured (MAIL_MAILER).', [
                'user_id' => $user->id,
                'purpose' => $purpose,
            ]);
        }

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'portal_user.set_password_link_issued',
            actorUserId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
            companyId: $user->company_id === null ? null : (int) $user->company_id,
            auditableType: User::class,
            auditableId: (int) $user->id,
            newValues: [
                'purpose' => $purpose,
                'expires_at' => $expiresAt->toIso8601String(),
                'emailed' => $emailed,
                'issued_by_side' => 'merchant_portal',
            ],
            metadata: [
                'mail_configured' => $mailConfigured,
                'mail_failed' => $emailError !== null,
            ],
        ));

        return new SetPasswordLink(
            url: $url,
            expiresAt: $expiresAt,
            purpose: $purpose,
            emailed: $emailed,
            mailConfigured: $mailConfigured,
            emailError: $emailError,
        );
    }

    /**
     * Which link to resend to a user with NO usable password: a user who
     * was reset (or used forgot-password) keeps getting 60-minute reset
     * links; a user who was only ever invited gets the invite again.
     */
    public static function purposeForUserWithoutPassword(User $user): string
    {
        $latest = PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->value('purpose');

        return in_array($latest, ['reset', 'forgot'], true) ? 'reset' : 'invite';
    }
}
