<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * LAUNCH-P5 — the business day is the Muscat day (config
 * pos.business_timezone), not the UTC day the database stores. Used for a
 * shift re-open ("same day"), the Hours report's days and window, and the
 * attendance edit form.
 */
final class BusinessTime
{
    public static function timezone(): string
    {
        $tz = config('pos.business_timezone', 'Asia/Muscat');

        return is_string($tz) && $tz !== '' ? $tz : 'Asia/Muscat';
    }

    /** The business date (Y-m-d) of a moment. */
    public static function day(DateTimeInterface|string $moment): string
    {
        return Carbon::parse($moment)->setTimezone(self::timezone())->format('Y-m-d');
    }

    /** Whether two moments fall on the same business day. */
    public static function sameDay(DateTimeInterface|string $a, DateTimeInterface|string $b): bool
    {
        return self::day($a) === self::day($b);
    }

    /** A moment as business-local "Y-m-d H:i". */
    public static function local(DateTimeInterface|string|null $moment): ?string
    {
        return $moment === null ? null : Carbon::parse($moment)->setTimezone(self::timezone())->format('Y-m-d H:i');
    }

    /** A business-local "Y-m-d H:i" as a UTC moment. */
    public static function fromLocal(string $local): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $local, self::timezone())->utc();
    }

    /**
     * The UTC bounds of business dates [from, to] inclusive.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(string $fromDate, string $toDate): array
    {
        return [
            Carbon::parse($fromDate, self::timezone())->startOfDay()->utc(),
            Carbon::parse($toDate, self::timezone())->endOfDay()->utc(),
        ];
    }
}
