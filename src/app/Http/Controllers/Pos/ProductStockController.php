<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\AdjustProductStockAction;
use App\Actions\Pos\Inventory\AllocateProductStockAction;
use App\Actions\Pos\Inventory\ReceiveAndDistributeProductStockAction;
use App\Actions\Pos\Inventory\ReceiveProductStockAction;
use App\Actions\Pos\Inventory\TransferProductStockAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\AdjustProductStockRequest;
use App\Http\Requests\Pos\Inventory\AllocateProductStockRequest;
use App\Http\Requests\Pos\Inventory\ReceiveAndDistributeProductStockRequest;
use App\Http\Requests\Pos\Inventory\ReceiveProductStockRequest;
use App\Http\Requests\Pos\Inventory\TransferProductStockRequest;
use App\Http\Resources\Pos\Inventory\ProductStockMovementResource;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockMovement;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

/**
 * Phase 7 — central pool + per-branch distribution for UNIT (finished-good)
 * products.
 *
 *   GET  /api/products/{product:uuid}/stock            → central + per-branch balances + recent ledger
 *   POST /api/products/{product:uuid}/stock/receive    → add to the central pool
 *   POST /api/products/{product:uuid}/stock/allocate   → distribute central → branches
 *   POST /api/products/{product:uuid}/stock/transfer   → move units branch → branch
 *   POST /api/products/{product:uuid}/stock/adjust      → correct central or a branch count
 *   GET  /api/products/{product:uuid}/stock/movements  → paginated ledger
 *
 * Scoped to the product's company. Mutations require the product to be in 'unit'
 * stock mode.
 */
class ProductStockController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly ReceiveProductStockAction $receive,
        private readonly ReceiveAndDistributeProductStockAction $receiveDistribute,
        private readonly AllocateProductStockAction $allocate,
        private readonly TransferProductStockAction $transfer,
        private readonly AdjustProductStockAction $adjust,
    ) {}

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($product);

        $companyId = $this->tenant->requiredId();

        // P-G5 — a scoped user sees only their branches' rows; the
        // central balance stays visible (read-only context) but central
        // ledger rows and other branches' balances do not.
        $allowed = $request->user()?->allowedBranchIds();

        // Read through the model so the decimal:3 cast formats consistently
        // (a query-builder value() would return SQLite's raw, un-padded number).
        $central = ProductStock::query()
            ->where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->first();

        $bpByBranch = BranchProduct::query()
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('branch_id');

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')
            ->get()
            ->map(function (Branch $b) use ($bpByBranch): array {
                $bp = $bpByBranch->get($b->id);

                return [
                    'branch_uuid' => $b->uuid,
                    'branch_name' => $b->name,
                    'stock_qty' => ($bp !== null && $bp->stock_qty !== null) ? (string) $bp->stock_qty : null,
                ];
            })
            ->values()
            ->all();

        $movements = ProductStockMovement::query()
            ->where('product_id', $product->id)
            // P-G5 — central (branch NULL) rows are HQ-only; whereIn
            // drops them for scoped users along with foreign branches.
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->with(['branch', 'recordedByUser'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => [
                'product_uuid' => $product->uuid,
                'stock_mode' => $product->stock_mode,
                'central_quantity' => $central !== null ? (string) $central->quantity : '0.000',
                'branches' => $branches,
                'recent_movements' => ProductStockMovementResource::collection($movements)->resolve($request),
            ],
        ]);
    }

    public function receive(ReceiveProductStockRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($product);
        $this->requireUnitProduct($product);
        // P-G5 — the central pool is an HQ resource.
        \App\Support\BranchScope::ensureUnrestricted($request->user(), 'The central pool is managed by accounts with access to all branches.');

        try {
            $this->receive->handle(
                $product,
                $request->input('quantity'),
                $request->input('note'),
                $request->user(),
                $request->input('total_cost'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $product);
    }

    /**
     * Receive a bulk quantity AND split it across branches in one call. Whatever
     * is not distributed stays in the central pool. `allocations` may be empty —
     * the operation then behaves like a plain Receive.
     */
    public function receiveDistribute(ReceiveAndDistributeProductStockRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($product);
        $this->requireUnitProduct($product);
        // P-G5 — receiving + distributing drains the HQ pool.
        \App\Support\BranchScope::ensureUnrestricted($request->user(), 'The central pool is managed by accounts with access to all branches.');

        $lines = [];
        foreach ((array) $request->input('allocations', []) as $row) {
            $branch = $this->resolveBranch($row['branch_uuid'] ?? null);
            if ($branch === null) {
                return response()->json(['message' => 'A selected branch was not found.'], 422);
            }
            $lines[] = ['branch' => $branch, 'quantity' => $row['quantity']];
        }

        try {
            $this->receiveDistribute->handle(
                $product,
                $request->input('quantity'),
                $lines,
                $request->input('note'),
                $request->user(),
                $request->input('total_cost'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $product);
    }

    public function allocate(AllocateProductStockRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($product);
        $this->requireUnitProduct($product);
        // P-G5 — allocation debits the HQ pool.
        \App\Support\BranchScope::ensureUnrestricted($request->user(), 'The central pool is managed by accounts with access to all branches.');

        $lines = [];
        foreach ((array) $request->input('allocations') as $row) {
            $branch = $this->resolveBranch($row['branch_uuid'] ?? null);
            if ($branch === null) {
                return response()->json(['message' => 'A selected branch was not found.'], 422);
            }
            $lines[] = ['branch' => $branch, 'quantity' => $row['quantity']];
        }

        try {
            $this->allocate->handle($product, $lines, $request->input('note'), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $product);
    }

    public function transfer(TransferProductStockRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($product);
        $this->requireUnitProduct($product);

        $from = $this->resolveBranch($request->input('from_branch_uuid'));
        $to = $this->resolveBranch($request->input('to_branch_uuid'));
        if ($from === null || $to === null) {
            return response()->json(['message' => 'Branch not found.'], 422);
        }

        // P-G5 — both sides of a transfer must be within the scope.
        \App\Support\BranchScope::ensureBranch($request->user(), $from);
        \App\Support\BranchScope::ensureBranch($request->user(), $to);

        try {
            $this->transfer->handle($product, $from, $to, $request->input('quantity'), $request->input('note'), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $product);
    }

    public function adjust(AdjustProductStockRequest $request, Product $product): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($product);
        $this->requireUnitProduct($product);

        $branch = null;
        if ($request->filled('branch_uuid')) {
            $branch = $this->resolveBranch($request->input('branch_uuid'));
            if ($branch === null) {
                return response()->json(['message' => 'Branch not found.'], 422);
            }
        }

        // P-G5 — a branch adjustment needs that branch in scope; a
        // CENTRAL adjustment (no branch) is an HQ act.
        if ($branch !== null) {
            \App\Support\BranchScope::ensureBranch($request->user(), $branch);
        } else {
            \App\Support\BranchScope::ensureUnrestricted($request->user(), 'The central pool is managed by accounts with access to all branches.');
        }

        try {
            $this->adjust->handle($product, $branch, $request->input('signed_quantity'), $request->input('note'), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $product);
    }

    public function movements(Request $request, Product $product): LengthAwarePaginator
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($product);

        // P-G5 — scoped users read their branches' rows only (central
        // NULL-branch rows are HQ-only).
        $allowed = $request->user()?->allowedBranchIds();

        $query = ProductStockMovement::query()
            ->where('product_id', $product->id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->with(['branch', 'recordedByUser']);

        if ($request->filled('type')) {
            $query->where('movement_type', $request->query('type'));
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        return $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (ProductStockMovement $m): array => (new ProductStockMovementResource($m))->resolve($request));
    }

    // ---- helpers ----------------------------------------------

    private function resolveBranch(?string $uuid): ?Branch
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return Branch::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('uuid', $uuid)
            ->first();
    }

    private function requireUnitProduct(Product $product): void
    {
        // P-G1: cooked products sell from the same branch shelf stock as
        // unit products (production fills it, sales drain it), so the
        // stock dialog — balances, adjustments, history — applies to both.
        if (! in_array($product->stock_mode, ['unit', 'cooked'], true)) {
            abort(response()->json([
                'message' => 'Unit stock is only tracked for finished-good (unit) or cooked products. Set the product to "Unit / finished good" or "Cooked" first.',
            ], 422));
        }
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Product $product): void
    {
        if ((int) $product->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
