<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 6d — Discount target type (blueprint §10.7).
 *
 * Polymorphic-ish across two tables:
 *
 *   product   — pos_discount_targets.target_id points to a
 *               pos_products.id
 *   category  — points to a pos_product_categories.id
 */
enum DiscountTargetType: string
{
    case Product = 'product';
    case Category = 'category';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
