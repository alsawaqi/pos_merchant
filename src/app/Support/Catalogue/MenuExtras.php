<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use Illuminate\Validation\Validator;

/**
 * LAUNCH review add-on (owner decisions D10 and D11) — the rules shared by
 * every catalogue write that carries the new product fields:
 *
 *   - on_sale_from / on_sale_until: limited-time dates, 'YYYY-MM-DD' calendar
 *     days in Asia/Muscat, both inclusive, NULL = no bound. They are not the
 *     daily hours (available_from / available_until, 'HH:MM:SS').
 *   - cooking_minutes: 0..240, NULL = not set (0 = ready at once).
 *
 * The product requests, the wizard and the combo editor compose these, so the
 * paths can never drift. The database repeats both checks (pos_admin CHECKs).
 */
final class MenuExtras
{
    public const MAX_COOKING_MINUTES = 240;

    /**
     * @return array<string, list<string>>
     */
    public static function productRules(bool $partial = false, string $prefix = ''): array
    {
        $head = $partial ? ['sometimes', 'nullable'] : ['nullable'];

        return [
            $prefix.'on_sale_from' => [...$head, 'date_format:Y-m-d'],
            $prefix.'on_sale_until' => [...$head, 'date_format:Y-m-d'],
            $prefix.'cooking_minutes' => [...$head, 'integer', 'between:0,'.self::MAX_COOKING_MINUTES],
        ];
    }

    /**
     * "Until" must not be before "From" (both inclusive, so the same day is
     * a one-day item). Values already refused by the format rule are skipped.
     */
    public static function checkDates(Validator $v, mixed $from, mixed $until, string $untilKey): void
    {
        if (! self::isDay($from) || ! self::isDay($until) || $v->errors()->has($untilKey)) {
            return;
        }
        if ((string) $until < (string) $from) {
            $v->errors()->add($untilKey, 'The "Until" date must be on or after the "From" date.');
        }
    }

    /** A blank value from a form means "no bound". */
    public static function day(mixed $value): ?string
    {
        return self::isDay($value) ? (string) $value : null;
    }

    /** 0..240, or NULL when not set ('' from a form means not set). */
    public static function minutes(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function isDay(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
