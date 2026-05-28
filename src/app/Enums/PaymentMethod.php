<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 7a — payment tender methods (blueprint §10.8 + §16).
 *
 *   cash         — settles offline immediately
 *   card         — via bank Soft POS APK; requires connectivity
 *   split_part   — one part of a multi-tender order. Used when
 *                  the order has 2+ payments and the merchant
 *                  wants reports to distinguish "the card half"
 *                  from "the cash half" by labelling both as
 *                  split_part
 *   loyalty      — points / wallet redemption (Phase 6b)
 *   gift         — gift card / voucher
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case SplitPart = 'split_part';
    case Loyalty = 'loyalty';
    case Gift = 'gift';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
