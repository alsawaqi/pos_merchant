<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\AdjustIngredientStockAction;
use App\Actions\Pos\Inventory\AllocateIngredientStockAction;
use App\Actions\Pos\Inventory\ReceiveAndDistributeIngredientStockAction;
use App\Actions\Pos\Inventory\ReceiveIngredientStockAction;
use App\Actions\Pos\Inventory\TransferStockAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\AdjustIngredientStockRequest;
use App\Http\Requests\Pos\Inventory\AllocateIngredientStockRequest;
use App\Http\Requests\Pos\Inventory\ReceiveAndDistributeIngredientStockRequest;
use App\Http\Requests\Pos\Inventory\ReceiveIngredientStockRequest;
use App\Http\Requests\Pos\Inventory\TransferIngredientStockRequest;
use App\Http\Resources\Pos\Inventory\IngredientStockMovementResource;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StockMovement;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

/**
 * P-G4 — central warehouse + per-branch distribution for INGREDIENTS, the
 * ingredient twin of {@see ProductStockController} ("buy 100 kg of sugar
 * once, then split 20/20/25 to branches").
 *
 *   GET  /api/ingredients/{ingredient:uuid}/stock              → central + per-branch balances + recent ledger
 *   POST /api/ingredients/{ingredient:uuid}/stock/receive      → add to the central warehouse
 *   POST /api/ingredients/{ingredient:uuid}/stock/receive-distribute → receive + split in one call
 *   POST /api/ingredients/{ingredient:uuid}/stock/allocate     → distribute warehouse → branches
 *   POST /api/ingredients/{ingredient:uuid}/stock/transfer     → move stock branch → branch (a real BranchTransfer)
 *   POST /api/ingredients/{ingredient:uuid}/stock/adjust       → correct central or a branch balance
 *   GET  /api/ingredients/{ingredient:uuid}/stock/movements    → paginated ledger (central + branch rows)
 *
 * Scoped to the ingredient's company. Quantities are in the ingredient's
 * BASE unit (the dialog labels inputs with it).
 */
class IngredientStockController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly ReceiveIngredientStockAction $receive,
        private readonly ReceiveAndDistributeIngredientStockAction $receiveDistribute,
        private readonly AllocateIngredientStockAction $allocate,
        private readonly TransferStockAction $transfer,
        private readonly AdjustIngredientStockAction $adjust,
    ) {}

    public function show(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($ingredient);

        $companyId = $this->tenant->requiredId();

        // P-G5 — a scoped user sees only their branches' rows; the
        // central balance stays visible (read-only context) but central
        // ledger rows and other branches' balances do not.
        $allowed = $request->user()?->allowedBranchIds();

        // Read through the model so the decimal:3 cast formats consistently
        // (a query-builder value() would return SQLite's raw, un-padded number).
        $central = IngredientStock::query()
            ->where('company_id', $companyId)
            ->where('ingredient_id', $ingredient->id)
            ->first();

        $stockByBranch = BranchStock::query()
            ->where('ingredient_id', $ingredient->id)
            ->get()
            ->keyBy('branch_id');

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')
            ->get()
            ->map(function (Branch $b) use ($stockByBranch): array {
                $row = $stockByBranch->get($b->id);

                return [
                    'branch_uuid' => $b->uuid,
                    'branch_name' => $b->name,
                    // null = this branch has never stocked the ingredient.
                    'quantity' => $row !== null ? (string) $row->quantity : null,
                ];
            })
            ->values()
            ->all();

        $movements = StockMovement::query()
            ->where('ingredient_id', $ingredient->id)
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
                'ingredient_uuid' => $ingredient->uuid,
                'unit' => $ingredient->unit->value,
                'central_quantity' => $central !== null ? (string) $central->quantity : '0.000',
                'branches' => $branches,
                'recent_movements' => IngredientStockMovementResource::collection($movements)->resolve($request),
            ],
        ]);
    }

    public function receive(ReceiveIngredientStockRequest $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($ingredient);
        // P-G5 — the central warehouse is an HQ resource.
        \App\Support\BranchScope::ensureUnrestricted($request->user(), 'The central warehouse is managed by accounts with access to all branches.');

        try {
            $this->receive->handle(
                $ingredient,
                $request->input('quantity'),
                $request->input('note'),
                $request->user(),
                $request->input('total_cost'),
                $request->input('delivery_cost'),
                taxAmount: $request->input('tax_amount'),
                taxRate: $request->input('tax_rate'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $ingredient);
    }

    /**
     * Receive a bulk quantity AND split it across branches in one call.
     * Whatever is not distributed stays in the central warehouse.
     * `allocations` may be empty — the operation then behaves like a plain
     * Receive.
     */
    public function receiveDistribute(ReceiveAndDistributeIngredientStockRequest $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($ingredient);
        // P-G5 — receiving + distributing drains the HQ pool.
        \App\Support\BranchScope::ensureUnrestricted($request->user(), 'The central warehouse is managed by accounts with access to all branches.');

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
                $ingredient,
                $request->input('quantity'),
                $lines,
                $request->input('note'),
                $request->user(),
                $request->input('total_cost'),
                $request->input('delivery_cost'),
                taxAmount: $request->input('tax_amount'),
                taxRate: $request->input('tax_rate'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $ingredient);
    }

    public function allocate(AllocateIngredientStockRequest $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($ingredient);
        // P-G5 — allocation debits the HQ pool.
        \App\Support\BranchScope::ensureUnrestricted($request->user(), 'The central warehouse is managed by accounts with access to all branches.');

        $lines = [];
        foreach ((array) $request->input('allocations') as $row) {
            $branch = $this->resolveBranch($row['branch_uuid'] ?? null);
            if ($branch === null) {
                return response()->json(['message' => 'A selected branch was not found.'], 422);
            }
            $lines[] = ['branch' => $branch, 'quantity' => $row['quantity']];
        }

        try {
            $this->allocate->handle($ingredient, $lines, $request->input('note'), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $ingredient);
    }

    /**
     * Branch → branch move from the dialog. Delegates to the EXISTING
     * TransferStockAction with a single line, so it produces a regular
     * BranchTransfer header (visible on the Transfers tab), the paired
     * transfer_out / transfer_in movements, the audit row and the
     * no-overdraw guard — one code path for every ingredient transfer.
     */
    public function transfer(TransferIngredientStockRequest $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($ingredient);

        $from = $this->resolveBranch($request->input('from_branch_uuid'));
        $to = $this->resolveBranch($request->input('to_branch_uuid'));
        if ($from === null || $to === null) {
            return response()->json(['message' => 'Branch not found.'], 422);
        }

        // P-G5 — both sides of a transfer must be within the scope.
        \App\Support\BranchScope::ensureBranch($request->user(), $from);
        \App\Support\BranchScope::ensureBranch($request->user(), $to);

        try {
            $this->transfer->handle(
                $from,
                $to,
                [['ingredient_uuid' => (string) $ingredient->uuid, 'quantity' => $request->input('quantity')]],
                $request->user(),
                $request->input('note'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $ingredient);
    }

    public function adjust(AdjustIngredientStockRequest $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($ingredient);

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
            \App\Support\BranchScope::ensureUnrestricted($request->user(), 'The central warehouse is managed by accounts with access to all branches.');
        }

        try {
            $this->adjust->handle($ingredient, $branch, $request->input('signed_quantity'), $request->input('note'), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->show($request, $ingredient);
    }

    public function movements(Request $request, Ingredient $ingredient): LengthAwarePaginator
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($ingredient);

        // P-G5 — scoped users read their branches' rows only (central
        // NULL-branch rows are HQ-only).
        $allowed = $request->user()?->allowedBranchIds();

        $query = StockMovement::query()
            ->where('ingredient_id', $ingredient->id)
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
            ->through(fn (StockMovement $m): array => (new IngredientStockMovementResource($m))->resolve($request));
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

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Ingredient $ingredient): void
    {
        if ((int) $ingredient->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
