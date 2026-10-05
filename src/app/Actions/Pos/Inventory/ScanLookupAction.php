<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Models\Ingredient;
use App\Models\ItemBarcode;
use App\Models\Product;
use App\Support\Inventory\Containers;
use App\Support\Inventory\ItemCodes;
use App\Support\Inventory\Packs;

/**
 * LAUNCH review add-on (F2) — what a scanned (or typed) code is, for the scan
 * box on Purchases, Transfers, Counts, Waste, Restock requests and the three
 * list searches. Company-scoped; it returns the ITEM only (never a stock
 * balance, so a branch-scoped user learns nothing about another branch).
 *
 * Lookup order:
 *   1. a container / pack barcode (pos_item_barcodes) → the item + that
 *      container or pack (or the item alone);
 *   2. a product or physical item barcode (pos_products.barcode);
 *   3. an exact SKU (case-insensitive) on an ingredient, then a product.
 */
final readonly class ScanLookupAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(int $companyId, string $code): array
    {
        $code = ItemCodes::normalize($code);
        if ($code === '') {
            return ['found' => false, 'code' => $code];
        }

        $row = ItemBarcode::query()
            ->where('company_id', $companyId)
            ->where('barcode', $code)
            ->first();
        if ($row !== null) {
            if ($row->ingredient_id !== null) {
                $ingredient = Ingredient::query()->where('company_id', $companyId)->find($row->ingredient_id);
                if ($ingredient !== null) {
                    return $this->ingredient($ingredient, 'barcode', $row->container_id !== null ? (int) $row->container_id : null, $row->label);
                }
            } else {
                $product = Product::query()->where('company_id', $companyId)->find($row->product_id);
                if ($product !== null) {
                    return $this->product($product, 'barcode', $row->pack_id !== null ? (int) $row->pack_id : null, $row->label);
                }
            }
        }

        $product = Product::query()->where('company_id', $companyId)->where('barcode', $code)->first();
        if ($product !== null) {
            return $this->product($product, 'product_barcode', null, null);
        }

        $lower = mb_strtolower($code);
        $ingredient = Ingredient::query()->where('company_id', $companyId)->whereRaw('lower(sku) = ?', [$lower])->first();
        if ($ingredient !== null) {
            return $this->ingredient($ingredient, 'sku', null, null);
        }
        $product = Product::query()->where('company_id', $companyId)->whereRaw('lower(sku) = ?', [$lower])->first();
        if ($product !== null) {
            return $this->product($product, 'sku', null, null);
        }

        return ['found' => false, 'code' => $code];
    }

    /**
     * @return array<string, mixed>
     */
    private function ingredient(Ingredient $ingredient, string $matchedBy, ?int $containerId, ?string $label): array
    {
        $all = Containers::of($ingredient);
        $container = $containerId === null ? null : $all->first(static fn ($c): bool => (int) $c->id === $containerId);

        return [
            'found' => true,
            'matched_by' => $matchedBy,
            'item_type' => 'ingredient',
            'item' => [
                'uuid' => $ingredient->uuid,
                'name' => $ingredient->name,
                'name_ar' => $ingredient->name_ar,
                'sku' => $ingredient->sku,
                'unit' => $ingredient->unit?->value,
                'is_prep' => (bool) $ingredient->is_prep,
            ],
            'container' => $container === null ? null : [
                'uuid' => $container->uuid,
                'token' => $container->token(),
                'display_name' => Containers::displayName($container, $ingredient, $all, 'en'),
                'display_name_ar' => Containers::displayName($container, $ingredient, $all, 'ar'),
            ],
            'pack' => null,
            'label' => $label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(Product $product, string $matchedBy, ?int $packId, ?string $label): array
    {
        $all = Packs::of($product);
        $pack = $packId === null ? null : $all->first(static fn ($p): bool => (int) $p->id === $packId);

        return [
            'found' => true,
            'matched_by' => $matchedBy,
            'item_type' => $product->is_internal ? 'physical' : 'product',
            'item' => [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'name_ar' => $product->name_ar,
                'sku' => $product->sku,
                'stock_mode' => $product->stock_mode,
            ],
            'container' => null,
            'pack' => $pack === null ? null : [
                'uuid' => $pack->uuid,
                'pieces' => Containers::trim((string) $pack->pieces),
                'display_name' => Packs::displayName($pack, $all, 'en'),
                'display_name_ar' => Packs::displayName($pack, $all, 'ar'),
            ],
            'label' => $label,
        ];
    }
}
