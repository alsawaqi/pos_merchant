<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\ContainerBreakdownAction;
use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Actions\Pos\Inventory\RecordPrepWasteAction;
use App\Actions\Pos\Inventory\RecordWasteAction;
use App\Support\Inventory\ContainerAmount;
use App\Support\Inventory\Containers;
use Illuminate\Support\Facades\DB;
use App\Enums\MerchantPermission;
use App\Enums\WasteReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\RecordWasteRequest;
use App\Http\Resources\Pos\Inventory\WasteRecordResource;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\WasteRecord;
use App\Support\MerchantTenantContext;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase 5c — waste recording + the Waste tab.
 *
 *   GET    /api/branches/{branch:uuid}/waste            → paginated list
 *   POST   /api/branches/{branch:uuid}/waste            → record one
 *
 * Tenant + branch ownership re-checked in the controller before
 * any Action call. Read gates on InventoryView; write gates on
 * InventoryManage (waste recording is a destructive stock
 * change and lives under the same trust class as Adjustment).
 *
 * Optional filters on index:
 *   ?ingredient=<uuid>   → only that ingredient
 *   ?reason=<reason>     → only that reason taxonomy
 *   ?from=<iso8601>      → occurred_at >= from
 *   ?to=<iso8601>        → occurred_at <= to
 *   ?per_page=<n>        → page size (default 50, max 200)
 */
class WasteController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly RecordWasteAction $record,
        private readonly RecordPrepWasteAction $recordPrep,
        private readonly ContainerBreakdownAction $breakdown,
        private readonly IngredientUnitConverter $units,
    ) {}

    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfBranchNotInTenant($branch);

        $query = WasteRecord::query()
            ->where('branch_id', $branch->id)
            ->with(['ingredient', 'recordedBy', 'prepItem']);

        if ($request->filled('ingredient')) {
            $ingredientUuid = (string) $request->query('ingredient');
            $ingredientId = Ingredient::query()
                ->where('uuid', $ingredientUuid)
                ->where('company_id', $this->tenant->requiredId())
                ->value('id');
            // -1 sentinel — bogus / cross-tenant uuid silently
            // yields zero rows (no information leak).
            $query->where('ingredient_id', $ingredientId ?? -1);
        }

        if ($request->filled('reason')) {
            $reason = (string) $request->query('reason');
            // Validate against the enum so SQL injection /
            // typo'd values fail-closed instead of returning
            // an empty page.
            if (in_array($reason, WasteReason::values(), true)) {
                $query->where('reason', $reason);
            } else {
                $query->where('reason', '__never_matches__');
            }
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->query('to'));
        }

        $perPage = min((int) $request->query('per_page', 50), 200);

        // Resource collection → JSON { data, meta } (the Waste tab reads
        // waste.meta.*). A raw paginator serializes flat (no `meta`) and would
        // crash the tab on render once there is more than an empty list — the
        // same bug that made the Movements tab appear unclickable.
        return WasteRecordResource::collection(
            $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($perPage),
        );
    }

    public function store(RecordWasteRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfBranchNotInTenant($branch);

        $ingredient = Ingredient::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('uuid', $request->input('ingredient_uuid'))
            ->first();
        if ($ingredient === null) {
            return response()->json(['message' => 'Ingredient not found.'], 422);
        }

        // Parse occurred_at if provided. Laravel's date validator
        // accepts ISO8601; we hand a DateTimeInterface to the
        // action.
        $occurredAt = null;
        if ($request->filled('occurred_at')) {
            $occurredAt = new \DateTimeImmutable((string) $request->input('occurred_at'));
        }

        // LAUNCH-P3 P3-4 — a prep item has no stock: its waste is the waste
        // of its exploded raw ingredients, one event naming the prep item.
        if ($ingredient->isPrep()) {
            if ($request->filled('container_uuid')) {
                return response()->json(['message' => 'A prep item has no containers: enter the amount wasted.'], 422);
            }
            try {
                $result = $this->recordPrep->handle(
                    branch: $branch,
                    prep: $ingredient,
                    quantity: $request->input('quantity'),
                    reason: WasteReason::from((string) $request->input('reason')),
                    actor: $request->user(),
                    notes: $request->input('notes'),
                    occurredAt: $occurredAt,
                    unit: $request->input('unit'),
                );
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $records = $result['records']->each(fn (WasteRecord $r) => $r->load(['ingredient', 'branch', 'recordedBy', 'prepItem']));

            return response()->json([
                'data' => (new WasteRecordResource($records->first()))->resolve($request),
                'records' => WasteRecordResource::collection($records)->resolve($request),
                'prep_item' => ['uuid' => $ingredient->uuid, 'name' => $ingredient->name, 'quantity' => $result['quantity'], 'unit' => $ingredient->unit?->value],
                'total_cost' => $result['total_cost'],
                'waste_group_uuid' => $result['waste_group_uuid'],
                // Fix order 1, K3 — sell-but-warn: never refused on stock numbers.
                'warning' => $result['warning'],
            ], 201);
        }

        try {
            // LAUNCH review add-on (D3) — waste BY CONTAINER: pieces of one of
            // the item's containers; the amount defaults to pieces × size and
            // may only be lowered. The branch breakdown loses those containers
            // (never below 0) — on this path only, never on a count shortfall.
            $rows = [];
            $quantity = $request->input('quantity');
            $unit = $request->input('unit');
            if ($request->filled('container_uuid')) {
                $rows = ContainerAmount::rows($ingredient, [['container_uuid' => (string) $request->input('container_uuid'), 'pieces' => $request->input('pieces')]]);
                $quantity = (string) ContainerAmount::amount($ingredient, $rows, $quantity, is_string($unit) && $unit !== '' ? $unit : null, $this->units);
                $unit = null;
            } elseif (is_string($unit) && $unit !== '' && ($asContainer = Containers::resolve($ingredient, $unit)) !== null) {
                // Fix order B-1 (L4) — "2" typed in the container picked in
                // the unit list is 2 of that container, as on a transfer: the
                // breakdown loses them too.
                $rows = ContainerAmount::rows($ingredient, [['container_uuid' => (string) $asContainer->uuid, 'pieces' => $quantity]]);
                $quantity = (string) ContainerAmount::amount($ingredient, $rows, null, null, $this->units);
                $unit = null;
            }

            $result = DB::transaction(function () use ($branch, $ingredient, $quantity, $unit, $request, $occurredAt, $rows): array {
                $result = $this->record->record(
                    branch: $branch,
                    ingredient: $ingredient,
                    quantity: $quantity,
                    reason: WasteReason::from((string) $request->input('reason')),
                    actor: $request->user(),
                    notes: $request->input('notes'),
                    occurredAt: $occurredAt,
                    unit: $unit,
                    container: $rows[0]['container'] ?? null,
                    pieces: isset($rows[0]) ? (string) $rows[0]['pieces'] : null,
                );
                if ($rows !== []) {
                    $this->breakdown->take($ingredient, (int) $branch->id, ContainerBreakdownAction::leaves($ingredient, $rows), 'waste', $request->user(), [
                        'reference_type' => WasteRecord::class,
                        'reference_id' => (int) $result['record']->id,
                        'occurred_at' => $occurredAt,
                    ]);
                }

                return $result;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $waste = $result['record'];
        $waste->load(['ingredient', 'branch', 'recordedBy', 'prepItem']);

        return response()->json([
            'data' => (new WasteRecordResource($waste))->resolve($request),
            // Fix order 1, K3 — sell-but-warn: a waste the branch balance
            // cannot cover is recorded, and this says it is now below zero.
            'warning' => $result['warning'],
        ], 201);
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
