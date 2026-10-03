<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports\Support;

use Illuminate\Database\Query\Builder;

/**
 * LAUNCH-P4 B8 — the two revenue rules every report applies:
 *
 *   1. Combo children are never revenue. A combo line's chosen items are
 *      child order lines (parent_order_item_id set) with unit price and line
 *      total 0; the revenue sits on the combo line. Revenue and "qty sold"
 *      queries keep top-level lines only; stock, kitchen, cost and "items sold
 *      inside combos" read the children.
 *   2. Sales are reported excluding VAT. On an order with prices_include_tax
 *      the subtotal and line totals contain the VAT (grand_total already holds
 *      tax_total); the VAT is taken out. Exclusive orders are unchanged.
 *
 * Plain SQL fragments, valid on Postgres (production) and sqlite (tests).
 */
final class RevenueSql
{
    /** Keep top-level lines only (no combo children). */
    public static function topLevel(Builder $query, string $items = 'pos_order_items'): Builder
    {
        return $query->whereNull($items.'.parent_order_item_id');
    }

    /** An order's gross sales excluding VAT (subtotal, less the VAT inside it). */
    public static function orderGross(string $orders = 'pos_orders'): string
    {
        return "(CASE WHEN {$orders}.prices_include_tax THEN {$orders}.subtotal - {$orders}.tax_total ELSE {$orders}.subtotal END)";
    }

    /**
     * A line's revenue excluding VAT. On an inclusive order every line gives
     * up its share of the VAT: line × (grand − tax) / grand.
     */
    public static function lineRevenue(string $items = 'pos_order_items', string $orders = 'pos_orders', ?string $amount = null): string
    {
        $amount ??= "{$items}.line_total";

        return "(CASE WHEN {$orders}.prices_include_tax AND {$orders}.grand_total > 0 THEN {$amount} * ({$orders}.grand_total - {$orders}.tax_total) / {$orders}.grand_total ELSE {$amount} END)";
    }
}
