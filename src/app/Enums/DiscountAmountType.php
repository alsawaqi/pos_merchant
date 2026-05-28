<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6d — Discount amount type (blueprint §5.9).
 *
 *   percent — amount is a 0-100 percentage. e.g. amount=10
 *             means 10% off
 *   fixed   — amount is a decimal-3 OMR sum subtracted
 *             from the target
 */
enum DiscountAmountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
