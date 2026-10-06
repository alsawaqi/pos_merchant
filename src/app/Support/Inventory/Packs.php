<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\ItemBarcode;
use App\Models\Product;
use App\Models\ProductPack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH review add-on (D3) — a physical item's packs ("box holds 50 cups",
 * "carton holds 4 × box 50"). `pieces` is the item's pieces in ONE pack,
 * nesting included. Every lookup is scoped to ONE item.
 */
final class Packs
{
    /**
     * @return Collection<int, ProductPack>
     */
    public static function of(Product $product): Collection
    {
        return ProductPack::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public static function findByUuid(Product $product, ?string $uuid): ?ProductPack
    {
        if ($uuid === null || trim($uuid) === '') {
            return null;
        }

        return ProductPack::query()
            ->where('product_id', $product->id)
            ->where('uuid', strtolower(trim($uuid)))
            ->first();
    }

    /**
     * "box 50 pcs", "carton (4 × box 50 pcs)".
     *
     * @param  Collection<int, ProductPack>|null  $all
     */
    public static function displayName(ProductPack $pack, ?Collection $all = null, string $locale = 'en', int $depth = 0): string
    {
        $name = $locale === 'ar' && is_string($pack->name_ar) && trim($pack->name_ar) !== '' ? trim($pack->name_ar) : (string) $pack->name;
        $pieces = $locale === 'ar' ? 'قطعة' : 'pcs';
        if ($pack->contains_pack_id === null || $depth > Containers::MAX_DEPTH) {
            return sprintf('%s %s %s', $name, Containers::trim((string) $pack->pieces), $pieces);
        }
        $child = $all?->first(static fn (ProductPack $p): bool => (int) $p->id === (int) $pack->contains_pack_id)
            ?? ProductPack::withTrashed()->find($pack->contains_pack_id);
        if ($child === null) {
            return sprintf('%s %s %s', $name, Containers::trim((string) $pack->pieces), $pieces);
        }

        return sprintf('%s (%s × %s)', $name, Containers::trim((string) $pack->contains_quantity), self::displayName($child, $all, $locale, $depth + 1));
    }

    /**
     * Whether a pack's size is locked: bought on a purchase line, or held by
     * another live pack.
     */
    public static function isUsed(ProductPack $pack): bool
    {
        return DB::table('pos_purchase_receipt_lines')->where('pack_id', $pack->id)->exists()
            || ProductPack::query()->where('contains_pack_id', $pack->id)->exists()
            // LAUNCH packaging add-on (fix order PK-B1, M1) — a live per-order
            // packaging line typed in this pack ("1 × pack of 50").
            || DB::table('pos_order_packaging_lines')
                ->where('product_id', $pack->product_id)
                ->whereNull('deleted_at')
                ->whereIn('entered_unit', [ContainerToken::encode((string) $pack->uuid), ContainerToken::PREFIX.$pack->uuid])
                ->exists();
    }

    /**
     * @param  Collection<int, ProductPack>  $all
     * @param  Collection<int, ItemBarcode>  $barcodes
     * @return array<string, mixed>
     */
    public static function present(ProductPack $pack, Collection $all, Collection $barcodes, ?bool $locked = null): array
    {
        $child = $pack->contains_pack_id === null ? null : $all->first(static fn (ProductPack $p): bool => (int) $p->id === (int) $pack->contains_pack_id);

        return [
            'uuid' => $pack->uuid,
            'name' => $pack->name,
            'name_ar' => $pack->name_ar,
            'pieces' => Containers::trim((string) $pack->pieces),
            'contains_pack_uuid' => $child?->uuid,
            'contains_quantity' => $pack->contains_quantity !== null ? Containers::trim((string) $pack->contains_quantity) : null,
            'display_name' => self::displayName($pack, $all, 'en'),
            'display_name_ar' => self::displayName($pack, $all, 'ar'),
            'size_locked' => $locked,
            'barcodes' => $barcodes
                ->filter(static fn (ItemBarcode $b): bool => (int) ($b->pack_id ?? 0) === (int) $pack->id)
                ->map(static fn (ItemBarcode $b): array => $b->summary())
                ->values()
                ->all(),
        ];
    }
}
