<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\TableInsightsAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Table;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Dine-in table insights (v2) — the merchant's per-table record.
 *
 *   GET /api/table-insights?branch_id=&date_from=&date_to=
 *        → every table of a branch with its window aggregates (overview)
 *   GET /api/table-insights/{table:uuid}?date_from=&date_to=
 *        → one table's full record (KPIs, sittings, customers, trend)
 *
 * Both reports.view gated + tenant-scoped (cross-tenant id = 404). A
 * branch-restricted user ({@see \App\Models\User::allowedBranchIds()}) is
 * confined to their own branches. The default window is the trailing 90
 * days when no date range is supplied (e.g. the floor-plan deep-link).
 */
class TableInsightsController extends Controller
{
    /** Trailing window applied when the caller omits a date range. */
    private const DEFAULT_WINDOW_DAYS = 90;

    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly TableInsightsAction $insights,
    ) {}

    /**
     * GET /api/table-insights — overview for one branch.
     */
    public function overview(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $branch = Branch::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('id', (int) $validated['branch_id'])
            ->first();
        if ($branch === null) {
            abort(404);
        }

        // P-G5 — a branch-restricted user can only read their own branches.
        $allowed = $request->user()?->allowedBranchIds();
        if ($allowed !== null && ! in_array((int) $branch->id, $allowed, true)) {
            abort(404);
        }

        [$from, $to] = $this->window($validated);

        return response()->json([
            'data' => $this->insights->overview($this->tenant->requiredId(), $branch, $from, $to),
        ]);
    }

    /**
     * GET /api/table-insights/{table:uuid} — one table's full record.
     */
    public function show(Request $request, Table $table): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        // UUID binding doesn't scope by tenant; a foreign table 404s.
        if ((int) $table->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }

        // P-G5 — branch-restricted users: the table's branch must be in scope.
        $allowed = $request->user()?->allowedBranchIds();
        if ($allowed !== null) {
            $table->loadMissing('floor:id,branch_id');
            $branchId = $table->floor?->branch_id;
            if ($branchId === null || ! in_array((int) $branchId, $allowed, true)) {
                abort(404);
            }
        }

        $validated = $request->validate([
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ]);

        [$from, $to] = $this->window($validated);

        return response()->json([
            'data' => $this->insights->detail($this->tenant->requiredId(), $table, $from, $to),
        ]);
    }

    /**
     * Resolve [from, to] from the validated payload. to defaults to now,
     * from to (to − 90 days). Both inclusive (startOfDay / endOfDay).
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(array $validated): array
    {
        $to = ! empty($validated['date_to'])
            ? Carbon::parse((string) $validated['date_to'])->endOfDay()
            : Carbon::now()->endOfDay();

        $from = ! empty($validated['date_from'])
            ? Carbon::parse((string) $validated['date_from'])->startOfDay()
            : $to->copy()->subDays(self::DEFAULT_WINDOW_DAYS - 1)->startOfDay();

        return [$from, $to];
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}
