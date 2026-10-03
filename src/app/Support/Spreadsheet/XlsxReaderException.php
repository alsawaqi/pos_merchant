<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use RuntimeException;

/**
 * LAUNCH-P4 B6 — a spreadsheet the importer cannot read. The code is stable
 * (the portal translates it); the message is a plain English fallback.
 *
 * Codes: not_xlsx, corrupt, encrypted, unsupported, too_large, no_sheet,
 * too_many_rows, not_utf8, empty.
 */
final class XlsxReaderException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
