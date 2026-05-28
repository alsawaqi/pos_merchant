<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6d — Discount scope (blueprint §5.9).
 *
 *   product   — applies to specific products (via
 *               pos_discount_targets where target_type=product).
 *               Affects line_discount on matching items
 *   category  — applies to all products in specific categories
 *               (target_type=category). Affects line_discount
 *               on items whose product->category matches
 *   order     — applies to the whole order total (after line
 *               discounts). No target rows.
 */
enum DiscountScope: string
{
    case Product = 'product';
    case Category = 'category';
    case Order = 'order';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
