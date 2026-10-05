<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use RuntimeException;

/**
 * Fix order C-1, M1 — the "Can be removed" ticks a page loaded are no longer
 * the saved ones (another manager or another tab saved in between). Nothing
 * is written; the endpoint answers 409 so the page asks for a reload instead
 * of overwriting someone else's ticks.
 */
final class StaleRemovableTicksException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Someone changed the "Can be removed" ticks after this page was opened. Reload the page and try again.');
    }
}
