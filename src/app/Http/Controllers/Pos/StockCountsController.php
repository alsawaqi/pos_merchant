<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Actions\Pos\Inventory\SubmitStockCountAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\SubmitStockCountRequest;
use App\Http\Resources\Pos\Inventory\StockCountResource;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Support\Inventory\ContainerAmount;
use App\Support\Inventory\Containers;
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
        private readonly IngredientUnitConverter $units,
    ) {}

    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfBranchNotInTenant($branch);

        $perPage = min((int) $request->query('per_page', 15), 50);

        return StockCountResource::collection(
            StockCount::query()
                ->where('branch_id', $branch->id)
                ->with(['lines.ingredient', 'lines.containers', 'recordedByUser', 'recordedByPosStaff'])
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
            // LAUNCH-P3 P3-4 — a count never includes a prep item (no stock).
            if ($ingredient->isPrep()) {
                try {
                    $ingredient->ensureStocked();
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
            }
            $countedPieces = $line['counted_pieces'] ?? null;
            $countedUnits = $line['counted_units'] ?? null;
            $unit = $line['unit'] ?? null;

            // Fix order B-1 (L4) — "3" counted in a container picked in the
            // unit list is 3 of that container, as on a transfer: the count
            // sets the breakdown to them.
            if (empty($line['containers']) && is_string($unit) && $unit !== '' && $countedUnits !== null && $countedUnits !== '' && $countedPieces === null) {
                try {
                    $asContainer = Containers::resolve($ingredient, $unit);
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                if ($asContainer !== null) {
                    $line['containers'] = [['container_uuid' => (string) $asContainer->uuid, 'pieces' => $countedUnits]];
                    $countedUnits = null;
                    $unit = null;
                }
            }

            // LAUNCH review add-on (D2) — counted BY CONTAINER: the total is
            // Σ pieces × size, or the typed total when lower (part-used).
            $containers = [];
            if (! empty($line['containers'])) {
                try {
                    $containers = ContainerAmount::rows($ingredient, (array) $line['containers'], allowZero: true);
                    $countedUnits = (string) ContainerAmount::amount($ingredient, $containers, $countedUnits, is_string($unit) && $unit !== '' ? $unit : null, $this->units, allowZero: true);
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                $lines[] = ['ingredient' => $ingredient, 'counted_pieces' => null, 'counted_units' => $countedUnits, 'containers' => $containers];

                continue;
            }

            // LAUNCH item kind, A7 — counted in another unit of the item: 2.5 l
            // of a ml item, 3 crates, or containers ('@piece', which counts
            // as pieces so the line keeps them). Pieces sent win, as before.
            if (is_string($unit) && $unit !== '' && $countedUnits !== null && $countedPieces === null) {
                if ($unit === IngredientUnitConverter::PIECE_UNIT) {
                    $countedPieces = $countedUnits;
                    $countedUnits = null;
                } else {
                    try {
                        $countedUnits = $this->units->toBase($ingredient, $countedUnits, $unit);
                    } catch (RuntimeException $e) {
                        return response()->json(['message' => $e->getMessage()], 422);
                    }
                }
            }
            $lines[] = [
                'ingredient' => $ingredient,
                'counted_pieces' => $countedPieces,
                'counted_units' => $countedUnits,
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

        $count->load(['lines.ingredient', 'lines.containers', 'recordedByUser', 'recordedByPosStaff']);

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
