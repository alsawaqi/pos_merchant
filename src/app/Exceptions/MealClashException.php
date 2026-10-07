<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * LAUNCH combo add-on, fix order 2 (C-15) — a product create or category move
 * that would put a main in two active, on-sale meals. A RuntimeException, so
 * the product endpoints keep answering 422 with the message; the menu import
 * names the row it came from.
 */
final class MealClashException extends RuntimeException {}
