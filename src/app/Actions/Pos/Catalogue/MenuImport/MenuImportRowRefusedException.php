<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue\MenuImport;

use RuntimeException;
use Throwable;

/**
 * LAUNCH combo add-on, fix order 2 (C-15) — a row the catalogue refused while
 * the import was saving (the plan saw nothing wrong; the data changed under
 * it). The whole import rolls back; the controller answers 422 naming the row.
 */
final class MenuImportRowRefusedException extends RuntimeException
{
    public function __construct(public readonly int $row, public readonly string $name, string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Row %d (%s): %s', $row, $name, $reason), 0, $previous);
    }
}
