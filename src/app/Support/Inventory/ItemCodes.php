<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * LAUNCH review add-on (A4, A5; tester calls 11 and 12) — SKUs and barcodes,
 * unique per merchant ACROSS tables.
 *
 * SKU: one per ingredient (pos_ingredients.sku) and per product / physical
 * item (pos_products.sku). Unique per company, CASE-INSENSITIVE, across both
 * tables — an app check in both directions, because the two columns cannot
 * share one database constraint (and the product index is case-sensitive).
 * Blank = generated: ING-0001 for ingredients, PHY-0001 for physical items;
 * the next number is the highest of that prefix in either table + 1, skipping
 * any code already taken.
 *
 * Barcode: kept as a trimmed string (leading zeros stay). Unique per company
 * across pos_item_barcodes (live rows) and pos_products.barcode.
 *
 * Writers take the locks inside their transaction (pg_advisory_xact_lock per
 * company on Postgres), so two saves cannot claim the same code between the
 * check and the write. The SKU key is hashtext('pos_sku:<company id>') — the
 * SAME key pos_admin's SKU back-fill migration (2026_10_06_100004) takes, so
 * the two never race; barcodes use their own key,
 * hashtext('pos_barcode:<company id>'). A writer that needs both takes the
 * SKU lock first.
 */
final class ItemCodes
{
    public const PREFIX_INGREDIENT = 'ING';

    public const PREFIX_PHYSICAL = 'PHY';

    /** The per-company SKU lock (shared with the pos_admin back-fill). */
    public static function lockSku(int $companyId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['pos_sku:'.$companyId]);
        }
    }

    /** The per-company barcode lock. */
    public static function lockBarcode(int $companyId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['pos_barcode:'.$companyId]);
        }
    }

    /** A typed code as stored: trimmed; '' when blank. */
    public static function normalize(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    /**
     * Who already has this SKU (case-insensitive), or null: "the ingredient
     * Milk" / "the product Latte". The row being saved is left out.
     */
    public static function skuOwner(int $companyId, string $sku, ?int $exceptIngredientId = null, ?int $exceptProductId = null): ?string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $lower = mb_strtolower($sku);

        $ingredient = DB::table('pos_ingredients')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereRaw('lower(sku) = ?', [$lower])
            ->when($exceptIngredientId !== null, static fn ($q) => $q->where('id', '!=', $exceptIngredientId))
            ->value('name');
        if ($ingredient !== null) {
            return sprintf('the ingredient "%s"', $ingredient);
        }

        $product = DB::table('pos_products')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereRaw('lower(sku) = ?', [$lower])
            ->when($exceptProductId !== null, static fn ($q) => $q->where('id', '!=', $exceptProductId))
            ->first(['name', 'is_internal']);
        if ($product !== null) {
            return sprintf($product->is_internal ? 'the physical item "%s"' : 'the product "%s"', $product->name);
        }

        return null;
    }

    public static function skuMessage(string $owner): string
    {
        return sprintf('This SKU is already used by %s. SKUs are unique across ingredients, physical items and products.', $owner);
    }

    /**
     * Who already has this barcode, or null. $exceptBarcodeId leaves out a
     * pos_item_barcodes row; $exceptProductId leaves out a product's own
     * pos_products.barcode (the product being saved).
     */
    public static function barcodeOwner(int $companyId, string $barcode, ?int $exceptBarcodeId = null, ?int $exceptProductId = null): ?string
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $row = DB::table('pos_item_barcodes as b')
            ->leftJoin('pos_ingredients as i', 'i.id', '=', 'b.ingredient_id')
            ->leftJoin('pos_products as p', 'p.id', '=', 'b.product_id')
            ->where('b.company_id', $companyId)
            ->whereNull('b.deleted_at')
            ->where('b.barcode', $barcode)
            ->when($exceptBarcodeId !== null, static fn ($q) => $q->where('b.id', '!=', $exceptBarcodeId))
            ->first(['i.name as ingredient_name', 'p.name as product_name']);
        if ($row !== null) {
            return $row->ingredient_name !== null
                ? sprintf('the ingredient "%s"', $row->ingredient_name)
                : sprintf('the item "%s"', (string) $row->product_name);
        }

        $product = DB::table('pos_products')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('barcode', $barcode)
            ->when($exceptProductId !== null, static fn ($q) => $q->where('id', '!=', $exceptProductId))
            ->first(['name', 'is_internal']);
        if ($product !== null) {
            return sprintf($product->is_internal ? 'the physical item "%s"' : 'the product "%s"', $product->name);
        }

        return null;
    }

    public static function barcodeMessage(string $owner): string
    {
        return sprintf('This barcode is already used by %s.', $owner);
    }

    /**
     * The next free generated SKU with this prefix: highest PREFIX-#### in
     * either table + 1 (at least 4 digits), skipping a code used by ANY
     * ingredient or product row of the company — deleted rows included,
     * case-insensitive (the set pos_admin's back-fill skips). Call it under
     * {@see lockSku()}.
     */
    public static function nextSku(int $companyId, string $prefix): string
    {
        $used = [];
        foreach (['pos_ingredients', 'pos_products'] as $table) {
            foreach (DB::table($table)->where('company_id', $companyId)->whereNotNull('sku')->pluck('sku') as $sku) {
                $used[mb_strtolower(trim((string) $sku))] = true;
            }
        }

        $highest = 0;
        foreach (array_keys($used) as $code) {
            if (preg_match('/^'.preg_quote(mb_strtolower($prefix), '/').'-(\d+)$/', (string) $code, $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        $next = $highest + 1;
        while (isset($used[mb_strtolower(sprintf('%s-%04d', $prefix, $next))])) {
            $next++;
        }

        return sprintf('%s-%04d', $prefix, $next);
    }
}
