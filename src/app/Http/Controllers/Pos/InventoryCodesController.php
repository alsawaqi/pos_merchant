<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\BarcodeTakenException;
use App\Actions\Pos\Inventory\CreateItemBarcodeAction;
use App\Actions\Pos\Inventory\ScanLookupAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\ItemBarcode;
use App\Models\Product;
use App\Support\Inventory\Containers;
use App\Support\Inventory\Packs;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * LAUNCH review add-on (A5, D3, F) — barcodes and the scan box.
 *
 *   POST   /api/inventory/barcodes              → remember a barcode (inventory.manage)
 *   DELETE /api/inventory/barcodes/{uuid}       → forget one (inventory.manage)
 *   GET    /api/inventory/scan?code=            → what a code is (inventory.view)
 *   POST   /api/inventory/scan/link             → link an unknown code, then look
 *                                                 it up again (inventory.manage)
 *
 * A barcode names an ingredient (optionally one of its containers) or a
 * physical item / bought-in product (optionally one of its packs). Every
 * reference is resolved within the merchant's company, and a container / pack
 * within its item.
 */
class InventoryCodesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateItemBarcodeAction $create,
        private readonly ScanLookupAction $scan,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $data = $this->validateLink($request, 'barcode');

        try {
            $row = $this->createFrom($request, $data, (string) $data['barcode']);
        } catch (RuntimeException $e) {
            return $this->refused($e, 'barcode');
        }

        return response()->json(['data' => $row->summary()], 201);
    }

    public function destroy(Request $request, string $barcode): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $companyId = $this->tenant->requiredId();
        $row = ItemBarcode::query()->where('company_id', $companyId)->where('uuid', $barcode)->first();
        if ($row === null) {
            abort(404);
        }

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'inventory.barcode.deleted',
            actorUserId: $request->user()?->getKey(),
            companyId: $companyId,
            auditableType: ItemBarcode::class,
            auditableId: $row->id,
            oldValues: ['barcode' => $row->barcode, 'ingredient_id' => $row->ingredient_id, 'product_id' => $row->product_id],
        ));
        $row->delete();

        return response()->json(['data' => null], 204);
    }

    public function scan(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $code = (string) $request->query('code', '');
        if (mb_strlen($code) > 64) {
            return response()->json(['message' => 'A code has at most 64 characters.'], 422);
        }

        $result = $this->scan->handle($this->tenant->requiredId(), $code);
        if (! $result['found']) {
            $result['can_link'] = (bool) $request->user()?->can(MerchantPermission::InventoryManage->value);
        }

        return response()->json(['data' => $result]);
    }

    public function link(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $data = $this->validateLink($request, 'code');

        try {
            $this->createFrom($request, $data, (string) $data['code']);
        } catch (RuntimeException $e) {
            return $this->refused($e, 'code');
        }

        return response()->json(['data' => $this->scan->handle($this->tenant->requiredId(), (string) $data['code'])], 201);
    }

    /**
     * A refused barcode save. Fix order B-1 (M2) — when the code is on another
     * live item's barcode row, `barcode_uuid` names that row so the portal can
     * offer "Remove it from there" (DELETE /api/inventory/barcodes/{uuid}).
     */
    private function refused(RuntimeException $e, string $field): JsonResponse
    {
        $payload = ['message' => $e->getMessage(), 'errors' => [$field => [$e->getMessage()]]];
        if ($e instanceof BarcodeTakenException) {
            $payload['barcode_uuid'] = $e->barcodeUuid;
        }

        return response()->json($payload, 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateLink(Request $request, string $codeField): array
    {
        return $request->validate([
            $codeField => ['required', 'string', 'max:64'],
            'item_type' => ['required', 'string', 'in:ingredient,physical,product'],
            'item_uuid' => ['required', 'string', 'uuid'],
            'container_uuid' => ['nullable', 'string', 'max:64'],
            'pack_uuid' => ['nullable', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createFrom(Request $request, array $data, string $code): ItemBarcode
    {
        $companyId = $this->tenant->requiredId();
        $ingredient = null;
        $container = null;
        $product = null;
        $pack = null;

        if ($data['item_type'] === 'ingredient') {
            $ingredient = Ingredient::query()->where('company_id', $companyId)->where('uuid', $data['item_uuid'])->first();
            if ($ingredient === null) {
                throw new RuntimeException('Ingredient not found.');
            }
            if (! empty($data['container_uuid'])) {
                $container = Containers::findByUuid($ingredient, (string) $data['container_uuid']);
                if ($container === null) {
                    throw new RuntimeException('That container is not one of this ingredient\'s containers.');
                }
            }
        } else {
            $product = Product::query()->where('company_id', $companyId)->where('uuid', $data['item_uuid'])->first();
            if ($product === null || ($data['item_type'] === 'physical') !== (bool) $product->is_internal) {
                throw new RuntimeException('Item not found.');
            }
            // Fix order B-1 (L10) — a scanned code is for stock: only a
            // bought-in (unit) product or a physical item may carry one.
            if ($product->stock_mode !== 'unit' || $product->product_type === 'combo') {
                throw new RuntimeException('Only a bought-in product or a physical item can have a stock barcode. A cooked, made-to-order or combo product cannot.');
            }
            if (! empty($data['pack_uuid'])) {
                $pack = Packs::findByUuid($product, (string) $data['pack_uuid']);
                if ($pack === null) {
                    throw new RuntimeException('That pack is not one of this item\'s packs.');
                }
            }
        }

        return $this->create->handle($companyId, $code, $ingredient, $container, $product, $pack, $data['label'] ?? null, $request->user());
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}
