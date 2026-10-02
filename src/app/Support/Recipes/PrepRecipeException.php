<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use RuntimeException;

/**
 * LAUNCH-P3 P3-4 — a prep recipe that cannot be saved (a cycle, nesting
 * deeper than 3, no yield). A RuntimeException, so the controllers answer
 * 422 with the message.
 */
final class PrepRecipeException extends RuntimeException {}
