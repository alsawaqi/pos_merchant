<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\RecordWasteAction;
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
use Illuminate\Pagination\LengthAwarePaginator;
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
    ) {}

    public function index(Request $request, Branch $branch): LengthAwarePaginator
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfBranchNotInTenant($branch);

        $query = WasteRecord::query()
            ->where('branch_id', $branch->id)
            ->with(['ingredient', 'recordedBy']);

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

        return $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (WasteRecord $w): array => (new WasteRecordResource($w))->resolve($request));
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

        try {
            $waste = $this->record->handle(
                branch: $branch,
                ingredient: $ingredient,
                quantity: $request->input('quantity'),
                reason: WasteReason::from((string) $request->input('reason')),
                actor: $request->user(),
                notes: $request->input('notes'),
                occurredAt: $occurredAt,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $waste->load(['ingredient', 'branch', 'recordedBy']);

        return response()->json([
            'data' => (new WasteRecordResource($waste))->resolve($request),
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
