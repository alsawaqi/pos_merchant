<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\SubmitStockCountAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\SubmitStockCountRequest;
use App\Http\Resources\Pos\Inventory\StockCountResource;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase A (Additions §2.8) — day-end stock counts.
 *
 *   GET  /api/branches/{branch:uuid}/stock-counts   → recent counts (lines eager)
 *   POST /api/branches/{branch:uuid}/stock-counts   → submit + reconcile
 *
 * Read gated on InventoryView; submission on InventoryManage.
 */
class StockCountsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SubmitStockCountAction $submit,
    ) {}

    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfBranchNotInTenant($branch);

        $perPage = min((int) $request->query('per_page', 15), 50);

        return StockCountResource::collection(
            StockCount::query()
                ->where('branch_id', $branch->id)
                ->with(['lines.ingredient', 'recordedByUser', 'recordedByPosStaff'])
                ->orderByDesc('counted_at')
                ->orderByDesc('id')
                ->paginate($perPage),
        );
    }

    public function store(SubmitStockCountRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfBranchNotInTenant($branch);

        // Resolve every uuid tenant-scoped BEFORE the action; an
        // unknown / cross-tenant uuid is a clean 422, not a 500.
        /** @var array<string, Ingredient> $byUuid */
        $byUuid = Ingredient::query()
            ->where('company_id', $this->tenant->requiredId())
            ->whereIn('uuid', array_column($request->validated('lines'), 'ingredient_uuid'))
            ->get()
            ->keyBy('uuid')
            ->all();

        $lines = [];
        foreach ($request->validated('lines') as $line) {
            $ingredient = $byUuid[$line['ingredient_uuid']] ?? null;
            if ($ingredient === null) {
                return response()->json(['message' => 'Ingredient not found.'], 422);
            }
            $lines[] = [
                'ingredient' => $ingredient,
                'counted_pieces' => $line['counted_pieces'] ?? null,
                'counted_units' => $line['counted_units'] ?? null,
            ];
        }

        try {
            $count = $this->submit->handle(
                branch: $branch,
                lines: $lines,
                note: $request->input('note'),
                actor: $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $count->load(['lines.ingredient', 'recordedByUser', 'recordedByPosStaff']);

        return response()->json([
            'data' => (new StockCountResource($count))->resolve($request),
        ], 201);
    }

    // ---- helpers ----------------------------------------------

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
