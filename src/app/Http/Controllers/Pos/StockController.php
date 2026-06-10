<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\AdjustStockAction;
use App\Actions\Pos\Inventory\RecordPurchaseAction;
use App\Actions\Pos\Inventory\RestockAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\AdjustStockRequest;
use App\Http\Requests\Pos\Inventory\RecordPurchaseRequest;
use App\Http\Requests\Pos\Inventory\RestockRequest;
use App\Http\Resources\Pos\Inventory\BranchStockResource;
use App\Http\Resources\Pos\Inventory\IngredientPurchaseResource;
use App\Http\Resources\Pos\Inventory\StockMovementResource;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase 5a — per-branch stock balances + adjust + restock +
 * movement ledger.
 *
 *   GET   /api/branches/{branch:uuid}/stock                   → list balances
 *   POST  /api/branches/{branch:uuid}/stock/adjust            → signed delta
 *   POST  /api/branches/{branch:uuid}/stock/restock           → positive inflow
 *   GET   /api/branches/{branch:uuid}/stock-movements         → paginated ledger
 *
 * All endpoints scoped to a single branch via the route-bound
 * Branch model + refuseIfBranchNotInTenant.
 */
class StockController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly AdjustStockAction $adjust,
        private readonly RestockAction $restock,
        private readonly RecordPurchaseAction $purchase,
    ) {}

    /**
     * GET /api/branches/{branch:uuid}/stock
     *
     * Returns one row per ingredient that has EVER had a
     * balance at this branch. Sort: lowest qty first so the
     * UI surfaces restock priorities at the top.
     */
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfBranchNotInTenant($branch);

        $rows = BranchStock::query()
            ->where('branch_id', $branch->id)
            ->with('ingredient')
            ->orderBy('quantity')
            ->get();

        return BranchStockResource::collection($rows);
    }

    /**
     * POST /api/branches/{branch:uuid}/stock/adjust
     *
     * Manual correction. Signed delta + required reason note.
     */
    public function adjust(AdjustStockRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfBranchNotInTenant($branch);

        $ingredient = $this->resolveIngredient($request->input('ingredient_uuid'));
        if ($ingredient === null) {
            return response()->json(['message' => 'Ingredient not found.'], 422);
        }

        try {
            $movement = $this->adjust->handle(
                branch: $branch,
                ingredient: $ingredient,
                signedQuantity: $request->input('signed_quantity'),
                note: $request->input('note'),
                actor: $request->user(),
                unit: $request->input('unit'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $movement->load('ingredient');

        return response()->json([
            'data' => (new StockMovementResource($movement))->resolve($request),
        ], 201);
    }

    /**
     * POST /api/branches/{branch:uuid}/stock/restock
     *
     * Positive inflow. Optional supplier_uuid + unit_cost override.
     */
    public function restock(RestockRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfBranchNotInTenant($branch);

        $ingredient = $this->resolveIngredient($request->input('ingredient_uuid'));
        if ($ingredient === null) {
            return response()->json(['message' => 'Ingredient not found.'], 422);
        }

        $supplier = null;
        if ($request->filled('supplier_uuid')) {
            $supplier = Supplier::query()
                ->where('company_id', $this->tenant->requiredId())
                ->where('uuid', $request->input('supplier_uuid'))
                ->first();
            if ($supplier === null) {
                return response()->json(['message' => 'Supplier not found.'], 422);
            }
        }

        try {
            $movement = $this->restock->handle(
                branch: $branch,
                ingredient: $ingredient,
                quantity: $request->input('quantity'),
                unitCost: $request->input('unit_cost'),
                supplier: $supplier,
                note: $request->input('note'),
                actor: $request->user(),
                unit: $request->input('unit'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $movement->load('ingredient');

        return response()->json([
            'data' => (new StockMovementResource($movement))->resolve($request),
        ], 201);
    }

    /**
     * POST /api/branches/{branch:uuid}/stock/purchase
     *
     * Phase A (Additions §2.4) — piece-aware purchase batch.
     * Pieces and/or total units + the money paid; writes the batch
     * row, the restock movement, the exact-amount expense, and
     * updates the ingredient's ratio/cost (last batch wins).
     */
    public function purchase(RecordPurchaseRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfBranchNotInTenant($branch);

        $ingredient = $this->resolveIngredient($request->input('ingredient_uuid'));
        if ($ingredient === null) {
            return response()->json(['message' => 'Ingredient not found.'], 422);
        }

        $supplier = null;
        if ($request->filled('supplier_uuid')) {
            $supplier = Supplier::query()
                ->where('company_id', $this->tenant->requiredId())
                ->where('uuid', $request->input('supplier_uuid'))
                ->first();
            if ($supplier === null) {
                return response()->json(['message' => 'Supplier not found.'], 422);
            }
        }

        try {
            $purchase = $this->purchase->handle(
                branch: $branch,
                ingredient: $ingredient,
                pieces: $request->input('pieces'),
                units: $request->input('units'),
                totalPaid: $request->input('total_paid'),
                supplier: $supplier,
                note: $request->input('note'),
                actor: $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new IngredientPurchaseResource($purchase))->resolve($request),
        ], 201);
    }

    /**
     * GET /api/branches/{branch:uuid}/stock-movements
     *
     * Paginated movement ledger. Supports:
     *   ?ingredient=<uuid>   filter by ingredient
     *   ?type=<movement>     filter by movement_type
     *   ?per_page=<n>        page size (default 50, max 200)
     */
    public function movements(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfBranchNotInTenant($branch);

        $query = StockMovement::query()
            ->where('branch_id', $branch->id)
            ->with(['ingredient', 'recordedByUser']);

        if ($request->filled('ingredient')) {
            $ingredientUuid = (string) $request->query('ingredient');
            $ingredientId = Ingredient::query()
                ->where('uuid', $ingredientUuid)
                ->where('company_id', $this->tenant->requiredId())
                ->value('id');
            // -1 sentinel guarantees zero results on bogus /
            // cross-tenant uuid (no information leak).
            $query->where('ingredient_id', $ingredientId ?? -1);
        }

        if ($request->filled('type')) {
            $query->where('movement_type', $request->query('type'));
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        // Resource collection over the paginator → JSON shape { data, meta }
        // (the inventory page reads movements.meta.*). A raw LengthAwarePaginator
        // serializes FLAT (no `meta`), which made the Movements tab throw on
        // render — the tab appeared unclickable.
        return StockMovementResource::collection(
            $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage),
        );
    }

    // ---- helpers ----------------------------------------------

    private function resolveIngredient(?string $uuid): ?Ingredient
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        return Ingredient::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('uuid', $uuid)
            ->first();
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfBranchNotInTenant(Branch $branch): void
    {
        if ((int) $branch->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
