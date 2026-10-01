<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Set-password / reset-password email for a teammate whose login was
 * created or reset by their merchant's owner (owner follow-up
 * 2026-10-01). Same wording as pos_admin's SetPasswordLinkMail.
 * Sent synchronously so a failure is known at once; the sender can
 * always copy the link instead.
 */
class SetPasswordLinkMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly User $recipient,
        public readonly string $url,
        public readonly CarbonInterface $expiresAt,
        public readonly string $purpose,
        public readonly ?string $companyName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === 'invite'
                ? 'Set your MITHQAL Merchant Portal password'
                : 'Reset your MITHQAL Merchant Portal password',
            to: [(string) $this->recipient->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.set-password-link',
            with: [
                'recipientName' => $this->recipient->name,
                'companyName' => $this->companyName,
                'purpose' => $this->purpose,
                'url' => $this->url,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
