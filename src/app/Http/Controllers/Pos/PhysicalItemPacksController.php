<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\ItemBarcode;
use App\Models\Product;
use App\Models\ProductPack;
use App\Support\Inventory\Containers;
use App\Support\Inventory\PackSize;
use App\Support\Inventory\Packs;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH review add-on (D3) — a physical item's packs ("box holds 50 cups",
 * "carton holds 4 × box 50"), used in Purchases and the scan box.
 *
 *   GET    /api/physical-items/{product:uuid}/packs
 *   POST   /api/physical-items/{product:uuid}/packs
 *   PATCH  /api/physical-items/{product:uuid}/packs/{pack:uuid}
 *   DELETE /api/physical-items/{product:uuid}/packs/{pack:uuid}
 *
 * A pack holds a whole number (≥ 2) of pieces, or N (≥ 2) of another pack of
 * the SAME item (at most 3 deep); `pieces` is always the item's pieces in one.
 * The same name + size twice is refused; the same word with another size is
 * fine. Once a pack is used (a purchase line, or held by another pack) its
 * size is locked — the name stays editable. inventory.view / inventory.manage.
 */
class PhysicalItemPacksController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function index(Request $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotPhysicalItem($product);

        return response()->json(['data' => $this->present($product)]);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotPhysicalItem($product);
        $data = $request->validate($this->rules(true));

        try {
            $pack = DB::transaction(function () use ($product, $data, $request): ProductPack {
                [$pieces, $containsId, $containsQuantity] = $this->size($product, $data, null);
                $name = trim((string) $data['name']);
                $this->refuseDuplicate($product, $name, $pieces, $containsId, null);

                /** @var ProductPack $pack */
                $pack = ProductPack::query()->create([
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'name' => $name,
                    'name_ar' => isset($data['name_ar']) && trim((string) $data['name_ar']) !== '' ? trim((string) $data['name_ar']) : null,
                    'pieces' => $pieces,
                    'contains_pack_id' => $containsId,
                    'contains_quantity' => $containsQuantity,
                    'sort_order' => (int) ($data['sort_order'] ?? ProductPack::query()->where('product_id', $product->id)->count()),
                ]);
                $this->audit($request, 'inventory.product_pack.created', $pack, null, ['name' => $pack->name, 'pieces' => (string) $pack->pieces]);

                return $pack;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentOne($product, $pack)], 201);
    }

    public function update(Request $request, Product $product, string $pack): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotPhysicalItem($product);
        $row = Packs::findByUuid($product, $pack) ?? abort(404);
        $data = $request->validate($this->rules(false));

        try {
            DB::transaction(function () use ($product, $row, $data, $request): void {
                $old = ['name' => $row->name, 'name_ar' => $row->name_ar, 'pieces' => (string) $row->pieces];
                if (array_key_exists('name', $data)) {
                    $row->name = trim((string) $data['name']);
                }
                if (array_key_exists('name_ar', $data)) {
                    $row->name_ar = $data['name_ar'] !== null && trim((string) $data['name_ar']) !== '' ? trim((string) $data['name_ar']) : null;
                }
                if (array_key_exists('pieces', $data) || array_key_exists('contains_pack_uuid', $data)) {
                    [$pieces, $containsId, $containsQuantity] = $this->size($product, $data, $row);
                    $changed = abs((float) $pieces - (float) $row->pieces) > 1e-9 || (int) ($containsId ?? 0) !== (int) ($row->contains_pack_id ?? 0);
                    // Fix order B-1 (L6) — the pack row is locked before its use is checked.
                    if ($changed) {
                        ProductPack::query()->whereKey($row->id)->lockForUpdate()->first();
                    }
                    if ($changed && Packs::isUsed($row)) {
                        throw new RuntimeException('This pack is already used (purchases, or another pack holds it), so its size cannot change. Add a new pack instead.');
                    }
                    $row->pieces = $pieces;
                    $row->contains_pack_id = $containsId;
                    $row->contains_quantity = $containsQuantity;
                }
                $this->refuseDuplicate($product, (string) $row->name, (string) $row->pieces, $row->contains_pack_id !== null ? (int) $row->contains_pack_id : null, (int) $row->id);
                $row->save();
                $this->audit($request, 'inventory.product_pack.updated', $row, $old, ['name' => $row->name, 'name_ar' => $row->name_ar, 'pieces' => (string) $row->pieces]);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presentOne($product, $row->fresh())]);
    }

    public function destroy(Request $request, Product $product, string $pack): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotPhysicalItem($product);
        $row = Packs::findByUuid($product, $pack) ?? abort(404);

        $holder = ProductPack::query()->where('contains_pack_id', $row->id)->first();
        if ($holder !== null) {
            return response()->json(['message' => sprintf("'%s' holds this pack — remove '%s' first.", $holder->name, $holder->name)], 422);
        }

        DB::transaction(function () use ($row, $request): void {
            $this->audit($request, 'inventory.product_pack.deleted', $row, ['name' => $row->name, 'pieces' => (string) $row->pieces], null);
            \App\Support\Inventory\ItemCodes::forgetBarcodes((int) $row->company_id, ['pack_id' => (int) $row->id]);
            $row->delete();
        });

        return response()->json(['data' => null], 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $create): array
    {
        $sometimes = $create ? [] : ['sometimes'];

        return [
            'name' => [...$sometimes, ...($create ? ['required'] : []), 'string', 'max:32'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:32'],
            'pieces' => [...($create ? ['required_without:contains_pack_uuid'] : ['sometimes']), 'nullable', 'integer', 'min:2', 'max:'.PackSize::MAX_FACTOR],
            'contains_pack_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'contains_quantity' => ['required_with:contains_pack_uuid', 'nullable', 'integer', 'min:2', 'max:'.PackSize::MAX_FACTOR],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    private function size(Product $product, array $data, ?ProductPack $self): array
    {
        if (! empty($data['contains_pack_uuid'])) {
            $child = Packs::findByUuid($product, (string) $data['contains_pack_uuid']);
            if ($child === null) {
                throw new RuntimeException('A pack can only hold another pack of the same item.');
            }
            for ($node = $child, $depth = 1; $node !== null; $depth++) {
                if ($self !== null && (int) $node->id === (int) $self->id) {
                    throw new RuntimeException('A pack cannot hold itself.');
                }
                if ($depth >= Containers::MAX_DEPTH) {
                    throw new RuntimeException(sprintf('Packs nest at most %d deep.', Containers::MAX_DEPTH));
                }
                $node = $node->contains_pack_id !== null ? ProductPack::withTrashed()->find($node->contains_pack_id) : null;
            }
            $quantity = (int) $data['contains_quantity'];
            $pieces = Containers::decimal((string) $child->pieces)->multipliedBy($quantity);
            if ($pieces->isGreaterThan(PackSize::MAX_FACTOR)) {
                throw new RuntimeException(sprintf('A pack can hold at most %s pieces.', number_format(PackSize::MAX_FACTOR)));
            }

            return [(string) $pieces->toScale(4), (int) $child->id, number_format($quantity, 4, '.', '')];
        }

        $pieces = $data['pieces'] ?? null;
        if ($pieces === null && $self !== null) {
            $pieces = (string) $self->pieces;
        }
        if (! is_numeric($pieces) || (float) $pieces < 2 || floor((float) $pieces) != (float) $pieces) {
            throw new RuntimeException('A pack holds a whole number of pieces (2 or more).');
        }

        return [number_format((float) $pieces, 4, '.', ''), null, null];
    }

    private function refuseDuplicate(Product $product, string $name, string $pieces, ?int $containsId, ?int $ignoreId): void
    {
        $clash = ProductPack::query()
            ->where('product_id', $product->id)
            ->when($ignoreId !== null, static fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->get()
            ->first(static fn (ProductPack $p): bool => abs((float) $p->pieces - (float) $pieces) < 1e-9 && (int) ($p->contains_pack_id ?? 0) === (int) ($containsId ?? 0));
        if ($clash !== null) {
            throw new RuntimeException(sprintf("This item already has a '%s' of that size.", trim($name)));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function present(Product $product): array
    {
        $all = Packs::of($product);
        $barcodes = ItemBarcode::query()->where('product_id', $product->id)->get();

        return $all->map(static fn (ProductPack $p): array => Packs::present($p, $all, $barcodes, Packs::isUsed($p)))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOne(Product $product, ProductPack $pack): array
    {
        $all = Packs::of($product);
        $barcodes = ItemBarcode::query()->where('product_id', $product->id)->get();

        return Packs::present($pack, $all, $barcodes, Packs::isUsed($pack));
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(Request $request, string $event, ProductPack $pack, ?array $old, ?array $new): void
    {
        $this->writeAuditLog->handle(new AuditLogData(
            event: $event,
            actorUserId: $request->user()?->getKey(),
            companyId: $this->tenant->requiredId(),
            auditableType: ProductPack::class,
            auditableId: $pack->id,
            oldValues: $old,
            newValues: $new,
        ));
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotPhysicalItem(Product $product): void
    {
        if ((int) $product->company_id !== $this->tenant->requiredId() || ! $product->is_internal) {
            abort(404);
        }
    }
}
