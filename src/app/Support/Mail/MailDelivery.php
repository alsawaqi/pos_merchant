<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * LAUNCH-P1 P1-3 — "is real email delivery configured?" Mirror of
 * pos_admin's class — keep both in sync.
 *
 * With the `log` / `array` mailers, or SMTP without a host (the shipped
 * production template before the owner fills in the mailbox), a live
 * set-password link must NOT be "sent" into a log: the person who
 * created the login copies the link instead.
 */
final class MailDelivery
{
    private const NON_DELIVERING = ['log', 'array'];

    public static function configured(): bool
    {
        $mailer = config('mail.default');

        if (! is_string($mailer) || $mailer === '' || in_array($mailer, self::NON_DELIVERING, true)) {
            return false;
        }

        if ($mailer === 'smtp') {
            return (string) config('mail.mailers.smtp.host') !== ''
                || (string) config('mail.mailers.smtp.url') !== '';
        }

        return true;
    }
}
