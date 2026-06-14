<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Models\Production;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the GRAPHICAL kitchen-production view on the Production page —
 * the chart/timeline counterpart of the paginated audit list. Computes over the
 * SAME filters the list uses (branch / status / started_at range) so the charts
 * reflect the whole filtered window, not just the visible page.
 *
 *   - totals    : batches, pieces, finished / in-progress / cancelled counts,
 *                 average finished-batch duration
 *   - by_product: top products by pieces produced (name + name_ar)
 *   - by_staff  : top chefs (started_by) by batches
 *   - by_day    : produced pieces + batches per day (the trend line)
 *   - status_mix: batches per status (the status donut)
 *   - timeline  : the most recent batches (start → finish per batch) for the
 *                 Gantt-style production timeline
 *
 * Production is recorded online-only by pos_api; the merchant only reads it.
 * Quantities are pieces (decimal-3 strings, NOT money).
 */
final readonly class ProductionSummaryAction
{
    private const TOP_N = 8;

    private const TIMELINE_LIMIT = 60;

    /**
     * @param  list<int>|null  $allowedBranchIds  Actor branch scope (null = all)
     * @return array<string, mixed>
     */
    public function handle(
        int $companyId,
        ?array $allowedBranchIds,
        ?int $branchId,
        ?string $status,
        ?string $from,
        ?string $to,
    ): array {
        // A fresh, identically-filtered base query per aggregate. Columns are
        // qualified so they stay unambiguous after a join (pos_products also
        // carries company_id / status).
        $base = function () use ($companyId, $allowedBranchIds, $branchId, $status, $from, $to): Builder {
            $q = DB::table('pos_productions')->where('pos_productions.company_id', $companyId);
            if ($allowedBranchIds !== null) {
                $q->whereIn('pos_productions.branch_id', $allowedBranchIds);
            }
            if ($branchId !== null) {
                $q->where('pos_productions.branch_id', $branchId);
            }
            if ($status !== null && $status !== '') {
                $q->where('pos_productions.status', $status);
            }
            if ($from !== null && $from !== '') {
                $q->where('pos_productions.started_at', '>=', $from.' 00:00:00');
            }
            if ($to !== null && $to !== '') {
                $q->where('pos_productions.started_at', '<=', $to.' 23:59:59');
            }

            return $q;
        };

        $totals = $base()->selectRaw('
            COUNT(*) AS batches,
            COALESCE(SUM(pos_productions.quantity), 0) AS pieces,
            SUM(CASE WHEN pos_productions.status = \'finished\' THEN 1 ELSE 0 END) AS finished,
            SUM(CASE WHEN pos_productions.status = \'in_progress\' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN pos_productions.status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled,
            COALESCE(AVG(pos_productions.duration_seconds), 0) AS avg_duration
        ')->first();

        $byProduct = $base()
            ->join('pos_products', 'pos_products.id', '=', 'pos_productions.product_id')
            ->selectRaw('
                pos_products.name AS product_name,
                pos_products.name_ar AS product_name_ar,
                COUNT(*) AS batches,
                COALESCE(SUM(pos_productions.quantity), 0) AS pieces
            ')
            ->groupBy('pos_products.id', 'pos_products.name', 'pos_products.name_ar')
            ->orderByDesc('pieces')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'product_name' => (string) ($r->product_name ?? ''),
                'product_name_ar' => $r->product_name_ar !== null ? (string) $r->product_name_ar : null,
                'batches' => (int) $r->batches,
                'pieces' => number_format((float) $r->pieces, 3, '.', ''),
            ])->all();

        $byStaff = $base()
            ->join('pos_staff', 'pos_staff.id', '=', 'pos_productions.started_by_staff_id')
            ->selectRaw('
                pos_staff.name AS staff_name,
                COUNT(*) AS batches,
                COALESCE(SUM(pos_productions.quantity), 0) AS pieces
            ')
            ->groupBy('pos_staff.id', 'pos_staff.name')
            ->orderByDesc('batches')
            ->limit(self::TOP_N)
            ->get()
            ->map(static fn ($r): array => [
                'staff_name' => (string) ($r->staff_name ?? ''),
                'batches' => (int) $r->batches,
                'pieces' => number_format((float) $r->pieces, 3, '.', ''),
            ])->all();

        $driver = DB::connection()->getDriverName();
        $dayExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', pos_productions.started_at)"
            : "to_char(pos_productions.started_at, 'YYYY-MM-DD')";
        $byDay = $base()
            ->selectRaw("$dayExpr AS day, COUNT(*) AS batches, COALESCE(SUM(pos_productions.quantity), 0) AS pieces")
            ->groupByRaw($dayExpr)
            ->orderByRaw($dayExpr)
            ->get()
            ->map(static fn ($r): array => [
                'date' => (string) $r->day,
                'batches' => (int) $r->batches,
                'pieces' => number_format((float) $r->pieces, 3, '.', ''),
            ])->all();

        $statusMix = $base()
            ->selectRaw('pos_productions.status AS status, COUNT(*) AS count')
            ->groupBy('pos_productions.status')
            ->get()
            ->map(static fn ($r): array => [
                'status' => (string) $r->status,
                'count' => (int) $r->count,
            ])->all();

        return [
            'totals' => [
                'batches' => (int) ($totals?->batches ?? 0),
                'pieces' => number_format((float) ($totals?->pieces ?? 0), 3, '.', ''),
                'finished' => (int) ($totals?->finished ?? 0),
                'in_progress' => (int) ($totals?->in_progress ?? 0),
                'cancelled' => (int) ($totals?->cancelled ?? 0),
                'avg_duration_seconds' => (int) round((float) ($totals?->avg_duration ?? 0)),
            ],
            'by_product' => $byProduct,
            'by_staff' => $byStaff,
            'by_day' => $byDay,
            'status_mix' => $statusMix,
            'timeline' => $this->timeline($companyId, $allowedBranchIds, $branchId, $status, $from, $to),
        ];
    }

    /**
     * The most recent batches (newest first) — each carries start → finish for
     * the Gantt timeline. Uses the Eloquent model so dates come back as Carbon
     * and serialise as ISO-8601 like {@see \App\Http\Resources\Pos\Production\ProductionResource}.
     *
     * @param  list<int>|null  $allowedBranchIds
     * @return list<array<string, mixed>>
     */
    private function timeline(
        int $companyId,
        ?array $allowedBranchIds,
        ?int $branchId,
        ?string $status,
        ?string $from,
        ?string $to,
    ): array {
        return Production::query()
            ->with(['product:id,name,name_ar', 'startedByStaff:id,name'])
            ->where('company_id', $companyId)
            ->when($allowedBranchIds !== null, fn ($q) => $q->whereIn('branch_id', $allowedBranchIds))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->when($from !== null && $from !== '', fn ($q) => $q->where('started_at', '>=', $from.' 00:00:00'))
            ->when($to !== null && $to !== '', fn ($q) => $q->where('started_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::TIMELINE_LIMIT)
            ->get()
            ->map(static fn (Production $p): array => [
                'uuid' => $p->uuid,
                'product_name' => $p->product?->name,
                'product_name_ar' => $p->product?->name_ar,
                'status' => $p->status,
                'quantity' => (string) $p->quantity,
                'started_at' => $p->started_at?->toIso8601String(),
                'finished_at' => $p->finished_at?->toIso8601String(),
                'expires_at' => $p->expires_at?->toIso8601String(),
                'duration_seconds' => $p->duration_seconds,
                'staff_name' => $p->startedByStaff?->name,
            ])->all();
    }
}
