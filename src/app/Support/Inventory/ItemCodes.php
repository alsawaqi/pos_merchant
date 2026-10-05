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
 * Barcode: kept as a trimmed string, as typed (leading zeros stay). Unique per
 * company across pos_item_barcodes (live rows of live items) and
 * pos_products.barcode, compared CASE-INSENSITIVELY (fix order B-1, L3; the
 * database index is pos_admin's and case-sensitive, so this app check is the
 * rule).
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
     *
     * Fix order B-1 (L3, M2) — compared CASE-INSENSITIVELY, like SKUs (the
     * code is stored as typed); a barcode row of a DELETED ingredient or item
     * never counts (its item is gone, so it must never be named as the owner).
     */
    public static function barcodeOwner(int $companyId, string $barcode, ?int $exceptBarcodeId = null, ?int $exceptProductId = null): ?string
    {
        return self::barcodeHolder($companyId, $barcode, $exceptBarcodeId, $exceptProductId)['owner'] ?? null;
    }

    /**
     * The holder of a barcode: who ("the ingredient "Milk"") and, when it is a
     * pos_item_barcodes row, that row's uuid — the portal offers to remove it
     * from there. Null when the code is free.
     *
     * @return array{owner: string, barcode_uuid: ?string}|null
     */
    public static function barcodeHolder(int $companyId, string $barcode, ?int $exceptBarcodeId = null, ?int $exceptProductId = null): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }
        $lower = mb_strtolower($barcode);

        $row = DB::table('pos_item_barcodes as b')
            ->leftJoin('pos_ingredients as i', 'i.id', '=', 'b.ingredient_id')
            ->leftJoin('pos_products as p', 'p.id', '=', 'b.product_id')
            ->where('b.company_id', $companyId)
            ->whereNull('b.deleted_at')
            ->whereRaw('lower(b.barcode) = ?', [$lower])
            ->where(static function ($q): void {
                $q->where(static fn ($w) => $w->whereNotNull('b.ingredient_id')->whereNull('i.deleted_at'))
                    ->orWhere(static fn ($w) => $w->whereNotNull('b.product_id')->whereNull('p.deleted_at'));
            })
            ->when($exceptBarcodeId !== null, static fn ($q) => $q->where('b.id', '!=', $exceptBarcodeId))
            ->first(['b.uuid as barcode_uuid', 'i.name as ingredient_name', 'p.name as product_name', 'p.is_internal as product_internal']);
        if ($row !== null) {
            $owner = $row->ingredient_name !== null
                ? sprintf('the ingredient "%s"', $row->ingredient_name)
                : sprintf($row->product_internal ? 'the physical item "%s"' : 'the product "%s"', (string) $row->product_name);

            return ['owner' => $owner, 'barcode_uuid' => (string) $row->barcode_uuid];
        }

        $product = DB::table('pos_products')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereRaw('lower(barcode) = ?', [$lower])
            ->when($exceptProductId !== null, static fn ($q) => $q->where('id', '!=', $exceptProductId))
            ->first(['name', 'is_internal']);
        if ($product !== null) {
            return ['owner' => sprintf($product->is_internal ? 'the physical item "%s"' : 'the product "%s"', $product->name), 'barcode_uuid' => null];
        }

        return null;
    }

    public static function barcodeMessage(string $owner): string
    {
        return sprintf('This barcode is already used by %s.', $owner);
    }

    /**
     * Fix order B-1 (M2) — forget the barcodes of a deleted ingredient or
     * item (and of a deleted container / pack), so the code can be linked
     * again. Soft-deletes; call it inside the delete's transaction (it takes
     * the barcode lock).
     *
     * @param  array{ingredient_id?: int, container_id?: int, product_id?: int, pack_id?: int}  $owner
     */
    public static function forgetBarcodes(int $companyId, array $owner): int
    {
        self::lockBarcode($companyId);
        $query = DB::table('pos_item_barcodes')->where('company_id', $companyId)->whereNull('deleted_at');
        foreach ($owner as $column => $id) {
            $query->where($column, $id);
        }

        return $query->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Fix order B-1 (M2) — live rows of this code whose ingredient or item was
     * deleted before barcodes were forgotten on delete (older data): released
     * so the code can be linked again. Call it under {@see lockBarcode()}.
     */
    public static function releaseOrphans(int $companyId, string $barcode): void
    {
        $ids = DB::table('pos_item_barcodes as b')
            ->leftJoin('pos_ingredients as i', 'i.id', '=', 'b.ingredient_id')
            ->leftJoin('pos_products as p', 'p.id', '=', 'b.product_id')
            ->where('b.company_id', $companyId)
            ->whereNull('b.deleted_at')
            ->whereRaw('lower(b.barcode) = ?', [mb_strtolower(trim($barcode))])
            ->where(static function ($q): void {
                $q->where(static fn ($w) => $w->whereNotNull('b.ingredient_id')->where(static fn ($d) => $d->whereNotNull('i.deleted_at')->orWhereNull('i.id')))
                    ->orWhere(static fn ($w) => $w->whereNotNull('b.product_id')->where(static fn ($d) => $d->whereNotNull('p.deleted_at')->orWhereNull('p.id')));
            })
            ->pluck('b.id');
        if ($ids->isNotEmpty()) {
            DB::table('pos_item_barcodes')->whereIn('id', $ids->all())->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Fix order B-1 (L5) — a product / combo / imported product save claims
     * its SKU and barcode inside its own transaction: the per-company locks
     * (SKU first), then the cross-table checks again, so two saves (or an
     * ingredient and a product) cannot take the same code at the same moment.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function claimProductCodes(int $companyId, mixed $sku, mixed $barcode, ?int $exceptProductId = null): void
    {
        $sku = self::normalize($sku);
        $barcode = self::normalize($barcode);
        if ($sku !== '') {
            self::lockSku($companyId);
        }
        if ($barcode !== '') {
            self::lockBarcode($companyId);
        }
        $errors = [];
        if ($sku !== '' && ($owner = self::skuOwner($companyId, $sku, null, $exceptProductId)) !== null) {
            $errors['sku'] = self::skuMessage($owner);
        }
        if ($barcode !== '') {
            self::releaseOrphans($companyId, $barcode);
            if (($owner = self::barcodeOwner($companyId, $barcode, null, $exceptProductId)) !== null) {
                $errors['barcode'] = self::barcodeMessage($owner);
            }
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
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

        // Fix order B-1 (L2) — only codes the generator could have made count
        // (PREFIX- and 1 to 9 digits): a typed supplier SKU like
        // ING-99999999999999999999 or ING-12A never moves the sequence, so
        // the next number is never negative or malformed.
        $highest = 0;
        foreach (array_keys($used) as $code) {
            if (preg_match('/^'.preg_quote(mb_strtolower($prefix), '/').'-(\d{1,9})$/', (string) $code, $m) === 1) {
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
