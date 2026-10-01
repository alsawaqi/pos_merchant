<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Carbon\CarbonInterface;

/**
 * A freshly issued set-password link (owner follow-up 2026-10-01).
 * Mirror of pos_admin's class — same JSON shape, so both SPAs' "Copy
 * set-password link" dialogs read the same fields.
 *
 * The URL carries the RAW token. It is returned once, to the person who
 * issued it, and never stored: the database keeps only the hash.
 */
final readonly class SetPasswordLink
{
    public function __construct(
        public string $url,
        public CarbonInterface $expiresAt,
        public string $purpose,
        public bool $emailed,
        public bool $mailConfigured,
        public ?string $emailError = null,
    ) {}

    /**
     * @return array{url: string, expires_at: string, purpose: string, emailed: bool, mail_configured: bool, email_error: string|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'purpose' => $this->purpose,
            'emailed' => $this->emailed,
            'mail_configured' => $this->mailConfigured,
            'email_error' => $this->emailError,
        ];
    }
}
