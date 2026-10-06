<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\CreateProductAction;
use App\Actions\Pos\Catalogue\DeleteProductAction;
use App\Actions\Pos\Catalogue\UpdateProductAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\CreatePhysicalItemRequest;
use App\Http\Requests\Pos\Inventory\UpdatePhysicalItemRequest;
use App\Models\ItemBarcode;
use App\Models\Product;
use App\Support\Inventory\ItemCodes;
use App\Support\Inventory\Packs;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PD3a — physical items: things that CANNOT be eaten (cups, lids, boxes,
 * light bulbs, cleaning items). A first-class Inventory concept — created
 * and managed here, never in the catalogue:
 *
 *   GET    /api/physical-items                 → list (+ central pool qty)
 *   POST   /api/physical-items                 → create
 *   PATCH  /api/physical-items/{product:uuid}  → update
 *
 * Storage stays the proven piece-counting machinery (a pos_products row
 * with stock_mode 'unit' + is_internal=true, base_price forced 0 — the
 * column is NOT NULL and a physical item has no selling price), which is
 * what gives every item the central pool, Receive & Distribute, branch
 * counts, transfers and the PD2 receive-cost→expense flow for free via
 * ProductStockController. internal_purpose records the kind:
 * 'packaging' (used with food — offered by the product-composition
 * picker) vs 'general' (branch use — never attachable to food).
 *
 * Gated by inventory.view / inventory.manage — these are stock-room
 * concerns, not menu concerns. The catalogue product endpoints 404
 * internal rows entirely — this controller is the ONLY management
 * surface (incl. DELETE).
 */
class PhysicalItemsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateProductAction $create,
        private readonly UpdateProductAction $update,
        private readonly DeleteProductAction $delete,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryView);

        $rows = Product::query()
            ->where('pos_products.company_id', $this->tenant->requiredId())
            ->where('pos_products.is_internal', true)
            ->leftJoin('pos_product_stock', function ($join): void {
                $join->on('pos_product_stock.product_id', '=', 'pos_products.id')
                    ->on('pos_product_stock.company_id', '=', 'pos_products.company_id');
            })
            ->orderBy('pos_products.name')
            ->get([
                'pos_products.*',
                DB::raw('pos_product_stock.quantity as central_quantity'),
            ]);

        return response()->json([
            'data' => $rows->map(fn (Product $item): array => $this->present($item))->all(),
        ]);
    }

    public function store(CreatePhysicalItemRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $validated = $request->validated();
        $companyId = $this->tenant->requiredId();

        try {
            $item = DB::transaction(function () use ($validated, $request, $companyId): Product {
                // LAUNCH review add-on (A4) — the supplier's code, or PHY-0001.
                ItemCodes::lockSku($companyId);
                $sku = ItemCodes::normalize($validated['sku'] ?? null);
                if ($sku === '') {
                    $sku = ItemCodes::nextSku($companyId, ItemCodes::PREFIX_PHYSICAL);
                } elseif (($owner = ItemCodes::skuOwner($companyId, $sku)) !== null) {
                    throw new RuntimeException(ItemCodes::skuMessage($owner));
                }

                return $this->create->handle([
                    'name' => $validated['name'],
                    'name_ar' => $validated['name_ar'] ?? null,
                    'cost_price' => $validated['cost_price'] ?? null,
                    'low_stock_threshold' => $validated['low_stock_threshold'] ?? null,
                    'internal_purpose' => $validated['purpose'],
                    'sku' => $sku,
                    // The product-ness is an implementation detail — forced
                    // here, never chosen by the merchant.
                    'stock_mode' => 'unit',
                    'is_internal' => true,
                    'base_price' => 0,
                    'show_on_customer_tablet' => false,
                ], $request->user());
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($item)], 201);
    }

    /**
     * POST /api/physical-items/generate-skus — LAUNCH review add-on (A4): give
     * every physical item without a SKU a generated PHY-0001 (one per item,
     * the next free number each), under the per-company code lock.
     */
    public function generateSkus(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $companyId = $this->tenant->requiredId();

        $count = DB::transaction(function () use ($request, $companyId): int {
            ItemCodes::lockSku($companyId);
            $items = Product::query()
                ->where('company_id', $companyId)
                ->where('is_internal', true)
                ->where(static fn ($q) => $q->whereNull('sku')->orWhere('sku', ''))
                ->orderBy('id')
                ->get();
            foreach ($items as $item) {
                $this->update->handle($item, ['sku' => ItemCodes::nextSku($companyId, ItemCodes::PREFIX_PHYSICAL)], $request->user());
            }

            return $items->count();
        });

        return response()->json(['data' => ['generated' => $count]]);
    }

    public function update(UpdatePhysicalItemRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotPhysicalItem($product);

        $validated = $request->validated();
        $attributes = array_intersect_key($validated, array_flip([
            'name', 'name_ar', 'cost_price', 'low_stock_threshold', 'status',
        ]));
        if (array_key_exists('purpose', $validated)) {
            // 'general' promises "never attached to food" — refuse the
            // flip while products still consume this item (the sale-time
            // consumption is purpose-agnostic, so flipping would keep
            // draining stock while the UI claims it can't be attached).
            if ($validated['purpose'] === 'general' && ($product->internal_purpose ?? 'packaging') !== 'general') {
                $attachedTo = DB::table('pos_product_components')
                    ->where('component_product_id', $product->id)
                    ->count();
                if ($attachedTo > 0) {
                    return response()->json([
                        'message' => sprintf(
                            'This item is attached to %d product(s) as a physical item — detach it from their composition first.',
                            $attachedTo,
                        ),
                    ], 422);
                }
            }
            $attributes['internal_purpose'] = $validated['purpose'];
        }

        $companyId = $this->tenant->requiredId();
        try {
            $item = DB::transaction(function () use ($product, $attributes, $validated, $request, $companyId): Product {
                // LAUNCH review add-on (A4) — a typed SKU, or blank = keep the
                // current one; an item without a SKU gets one on this save.
                ItemCodes::lockSku($companyId);
                $sku = array_key_exists('sku', $validated) ? ItemCodes::normalize($validated['sku']) : '';
                if ($sku === '') {
                    $sku = $product->sku !== null && trim((string) $product->sku) !== ''
                        ? (string) $product->sku
                        : ItemCodes::nextSku($companyId, ItemCodes::PREFIX_PHYSICAL);
                } elseif (($owner = ItemCodes::skuOwner($companyId, $sku, null, (int) $product->id)) !== null) {
                    throw new RuntimeException(ItemCodes::skuMessage($owner));
                }
                $attributes['sku'] = $sku;

                return $this->update->handle($product, $attributes, $request->user());
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($item)]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotPhysicalItem($product);

        // Still attached to products = still consumed per sale; force a
        // deliberate detach before the row disappears from every list.
        $attachedTo = DB::table('pos_product_components')
            ->where('component_product_id', $product->id)
            ->count();
        if ($attachedTo > 0) {
            return response()->json([
                'message' => sprintf(
                    'This item is attached to %d product(s) as a physical item — detach it from their composition first.',
                    $attachedTo,
                ),
            ], 422);
        }

        // LAUNCH packaging add-on — on a per-order packaging list = still
        // taken with every order of that type.
        $packagingLists = DB::table('pos_order_packaging_lines')
            ->where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->count();
        if ($packagingLists > 0) {
            return response()->json([
                'message' => sprintf(
                    'This item is on %d order packaging list(s) — remove it from Inventory → Order packaging first.',
                    $packagingLists,
                ),
            ], 422);
        }

        $this->delete->handle($product, $request->user());

        return response()->json(['data' => null], 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Product $item): array
    {
        // LAUNCH review add-on (D3, A5) — its packs and barcodes.
        $packs = Packs::of($item);
        $barcodes = ItemBarcode::query()->where('product_id', $item->id)->get();

        return [
            'id' => $item->id,
            'uuid' => $item->uuid,
            'name' => $item->name,
            'name_ar' => $item->name_ar,
            // A4 — the supplier's code, or a generated PHY-0001.
            'sku' => $item->sku,
            'packs' => $packs->map(static fn ($p): array => Packs::present($p, $packs, $barcodes))->values()->all(),
            // Barcodes on one piece of the item (pack barcodes ride each pack).
            'barcodes' => $barcodes->filter(static fn ($b): bool => $b->pack_id === null)->map(static fn ($b): array => $b->summary())->values()->all(),
            // Legacy internal items (pre-PD3a) carry NULL = packaging.
            'purpose' => $item->internal_purpose ?? 'packaging',
            'cost_price' => $item->cost_price !== null ? (string) $item->cost_price : null,
            'low_stock_threshold' => $item->low_stock_threshold !== null ? (string) $item->low_stock_threshold : null,
            'status' => $item->status,
            'central_quantity' => number_format((float) ($item->getAttribute('central_quantity') ?? 0), 3, '.', ''),
        ];
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /** Tenant 404 first; a non-physical product 404s too (no existence leak). */
    private function refuseIfNotPhysicalItem(Product $product): void
    {
        if ((int) $product->company_id !== $this->tenant->requiredId() || ! $product->is_internal) {
            abort(404);
        }
    }
}
