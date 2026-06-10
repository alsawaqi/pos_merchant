<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Forgot-password email for a merchant portal user (Phase D7).
 * The raw reset token is embedded in the link — this is the ONLY
 * place it ever appears (the database stores only its SHA-256
 * hash). Mirrors pos_admin's MerchantPortalWelcomeMail pattern.
 *
 * Dev: MAIL_MAILER=log writes the rendered message to
 * storage/logs/laravel.log.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $recipient,
        // Raw, un-hashed reset token. Lives only in memory + this
        // email body — never stored anywhere queryable.
        public readonly string $resetToken,
        // Wall-clock expiry shown in the body so the recipient
        // knows how long they have to click.
        public readonly \DateTimeInterface $expiresAt,
    ) {}

    /**
     * Build the SPA link the recipient clicks. /reset-password is a
     * guest-only page in this app; it hashes the incoming token and
     * matches against pos_password_reset_tokens.token_hash.
     */
    public function resetUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/reset-password?token='.urlencode($this->resetToken).'&email='.urlencode((string) $this->recipient->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your MITHQAL Merchant Portal password',
            to: [(string) $this->recipient->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.password-reset',
            with: [
                'recipientName' => $this->recipient->name,
                'resetUrl' => $this->resetUrl(),
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
