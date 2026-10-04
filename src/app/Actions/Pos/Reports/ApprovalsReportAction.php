<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Support\BusinessTime;
use App\Support\MerchantTenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 B3 — Approvals report (owner decision 2: "records who approved
 * what"). Reads pos_approvals, which pos_api writes at sync: one row per
 * gated action (a manual discount above the limit, a comp, a gift, a void,
 * a table cancel, a pay-out, …) with the person who did it, who approved,
 * how (offline / online) and the server's verdict:
 *
 *   position_ok  — the person's position is ticked for it (no approval needed)
 *   verified     — an approver's PIN proof checked out
 *   failed       — the proof or another check failed (reason says which)
 *   missing      — needed an approval, none was sent
 *   unverifiable — the approver had no verifier on the server yet
 *   legacy       — sent by an old app build (not checked)
 *
 * failed / missing / unverifiable are the "problems" the page highlights.
 *
 * Fix order 1, F2 (1b) — so is a `legacy` row recorded AT OR AFTER its
 * device first sent `auth_v: 1` (created_at >= pos_devices.auth_v_seen_at):
 * once a device runs a P5 build, leaving the marker out is a downgrade, not
 * an old app. Rows from before the marker are the device's genuine old-build
 * history and are not problems. Flagged rows carry
 * `legacy_from_p5_device: true`.
 *
 * Filters: the shared date + branch filter (on approved_at, the device time;
 * rows without one fall back to when the server recorded them), plus
 * action, approver, actor and result ('problems' = the three above).
 * Newest first. The JSON endpoint pages the rows; the export takes them all.
 *
 * LAUNCH-P5 follow-up 1 — days are MUSCAT business days
 * (pos.business_timezone), like the Hours report: the date filter covers
 * Muscat midnight to midnight, each row carries its Muscat day and local
 * time, and `by_day` totals the filtered rows per Muscat day.
 */
final readonly class ApprovalsReportAction
{
    public const RESULTS = ['position_ok', 'verified', 'failed', 'missing', 'unverifiable', 'legacy'];

    public const PROBLEMS = ['failed', 'missing', 'unverifiable'];

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * The optional criteria from a query string, ignoring anything malformed
     * (the JSON endpoint validates them first; the export reuses this).
     *
     * @param  array<string, mixed>  $input
     * @return array{action?: string, approver_staff_id?: int, actor_staff_id?: int, result?: string}
     */
    public static function criteriaFrom(array $input): array
    {
        $out = [];
        if (is_string($input['action'] ?? null) && $input['action'] !== '' && strlen($input['action']) <= 64) {
            $out['action'] = $input['action'];
        }
        foreach (['approver_staff_id', 'actor_staff_id'] as $key) {
            if (isset($input[$key]) && is_numeric($input[$key]) && (int) $input[$key] > 0) {
                $out[$key] = (int) $input[$key];
            }
        }
        $result = $input['result'] ?? null;
        if (is_string($result) && ($result === 'problems' || in_array($result, self::RESULTS, true))) {
            $out['result'] = $result;
        }

        return $out;
    }

    /**
     * @param  array{action?: string, approver_staff_id?: int, actor_staff_id?: int, result?: string}  $criteria
     * @return array<string, mixed>
     */
    public function handle(ReportFilter $filter, array $criteria = [], ?int $page = null, int $perPage = 50, bool $forExport = false): array
    {
        $companyId = $this->tenant->requiredId();
        $branchScope = $filter->branchScope();
        $fromDate = $filter->dateFrom->format('Y-m-d');
        $toDate = $filter->dateTo->format('Y-m-d');
        [$from, $to] = BusinessTime::window($fromDate, $toDate);

        $window = DB::table('pos_approvals as a')
            ->where('a.company_id', $companyId)
            ->where(fn (Builder $q) => $q->whereBetween('a.approved_at', [$from, $to])
                ->orWhere(fn (Builder $q) => $q->whereNull('a.approved_at')
                    ->whereBetween('a.created_at', [$from, $to])));
        if ($branchScope !== null) {
            $window->whereIn('a.branch_id', $branchScope);
        }

        $filtered = clone $window;
        if (isset($criteria['action'])) {
            $filtered->where('a.action', $criteria['action']);
        }
        if (isset($criteria['approver_staff_id'])) {
            $filtered->where('a.approver_staff_id', $criteria['approver_staff_id']);
        }
        if (isset($criteria['actor_staff_id'])) {
            $filtered->where('a.actor_staff_id', $criteria['actor_staff_id']);
        }
        if (isset($criteria['result'])) {
            $criteria['result'] === 'problems'
                ? $filtered->where(fn (Builder $q) => $this->problemCondition($q))
                : $filtered->where('a.result', $criteria['result']);
        }

        // ---- Summary (of the filtered rows) ----
        $counts = (clone $filtered)->selectRaw('a.result AS result, COUNT(*) AS n')->groupBy('a.result')->pluck('n', 'result');
        $summary = ['total' => 0, 'problems' => 0];
        foreach (self::RESULTS as $result) {
            $summary[$result] = (int) ($counts[$result] ?? 0);
            $summary['total'] += $summary[$result];
        }
        foreach (self::PROBLEMS as $result) {
            $summary['problems'] += $summary[$result];
        }
        // F2 — legacy rows from devices that already run a P5 build.
        $summary['legacy_from_p5_devices'] = (clone $filtered)->where('a.result', 'legacy')
            ->whereExists(fn (Builder $q) => $this->p5Device($q))->count();
        $summary['problems'] += $summary['legacy_from_p5_devices'];

        // ---- Per Muscat day (of the filtered rows) ----
        $byDay = [];
        $dayRows = (clone $filtered)
            ->leftJoin('pos_devices as dv', 'dv.id', '=', 'a.device_id')
            ->get(['a.approved_at', 'a.created_at', 'a.result', 'dv.auth_v_seen_at']);
        foreach ($dayRows as $r) {
            $day = BusinessTime::day((string) ($r->approved_at ?? $r->created_at));
            $byDay[$day] ??= ['day' => $day, 'total' => 0, 'problems' => 0];
            $byDay[$day]['total']++;
            $byDay[$day]['problems'] += self::isProblem((string) $r->result, self::afterMarker($r->created_at, $r->auth_v_seen_at)) ? 1 : 0;
        }
        krsort($byDay);
        $byDay = array_values($byDay);

        // ---- Rows, newest first ----
        $rowsQuery = (clone $filtered)
            ->join('pos_branches as b', 'b.id', '=', 'a.branch_id')
            ->leftJoin('pos_staff as actor', 'actor.id', '=', 'a.actor_staff_id')
            ->leftJoin('pos_staff as approver', 'approver.id', '=', 'a.approver_staff_id')
            ->leftJoin('pos_devices as dv', 'dv.id', '=', 'a.device_id')
            ->selectRaw('
                dv.auth_v_seen_at AS device_auth_v_seen_at,
                a.id, a.uuid, a.approved_at, a.created_at, a.verified_at, a.branch_id, b.name AS branch_name,
                a.device_id, a.action, a.result, a.mode, a.method, a.subject_type, a.subject_uuid,
                a.amount, a.ref, a.reason, a.actor_staff_id, actor.name AS actor_name,
                a.approver_staff_id, approver.name AS approver_name
            ')
            ->orderByRaw('COALESCE(a.approved_at, a.created_at) DESC')
            ->orderByDesc('a.id');

        $total = $summary['total'];
        $meta = null;
        if (! $forExport && $page !== null) {
            $perPage = max(1, min(200, $perPage));
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $lastPage));
            $rowsQuery->forPage($page, $perPage);
            $meta = ['current_page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage, 'total' => $total];
        }

        $rows = $rowsQuery->get()->map(static fn ($r): array => [
            'id' => (int) $r->id,
            'uuid' => (string) $r->uuid,
            'approved_at' => $r->approved_at !== null ? (string) $r->approved_at : null,
            'recorded_at' => $r->created_at !== null ? (string) $r->created_at : null,
            // Muscat business day and local times (what the page shows).
            'day' => BusinessTime::day((string) ($r->approved_at ?? $r->created_at)),
            'approved_local' => BusinessTime::local($r->approved_at),
            'recorded_local' => BusinessTime::local($r->created_at),
            'branch_id' => (int) $r->branch_id,
            'branch_name' => (string) $r->branch_name,
            'action' => (string) $r->action,
            'result' => (string) $r->result,
            'problem' => self::isProblem((string) $r->result, self::afterMarker($r->created_at, $r->device_auth_v_seen_at)),
            'legacy_from_p5_device' => (string) $r->result === 'legacy' && self::afterMarker($r->created_at, $r->device_auth_v_seen_at),
            'mode' => (string) $r->mode,
            'method' => $r->method !== null ? (string) $r->method : null,
            'actor_staff_id' => $r->actor_staff_id !== null ? (int) $r->actor_staff_id : null,
            'actor_name' => $r->actor_name !== null ? (string) $r->actor_name : null,
            'approver_staff_id' => $r->approver_staff_id !== null ? (int) $r->approver_staff_id : null,
            'approver_name' => $r->approver_name !== null ? (string) $r->approver_name : null,
            'amount' => $r->amount !== null ? number_format((float) $r->amount, 3, '.', '') : null,
            'subject_type' => $r->subject_type !== null ? (string) $r->subject_type : null,
            'subject_uuid' => $r->subject_uuid !== null ? (string) $r->subject_uuid : null,
            'ref' => $r->ref !== null ? (string) $r->ref : null,
            'reason' => $r->reason !== null ? (string) $r->reason : null,
            'device_id' => $r->device_id !== null ? (int) $r->device_id : null,
        ])->all();

        $payload = [
            'window' => [
                'from' => $fromDate,
                'to' => $toDate,
                'timezone' => BusinessTime::timezone(),
                'branch_ids' => $branchScope,
                'action' => $criteria['action'] ?? null,
                'approver_staff_id' => $criteria['approver_staff_id'] ?? null,
                'actor_staff_id' => $criteria['actor_staff_id'] ?? null,
                'result' => $criteria['result'] ?? null,
            ],
            'summary' => $summary,
            'by_day' => $byDay,
            'rows' => $rows,
        ];

        if ($forExport) {
            return $payload;
        }

        // The filter choices: what occurs in the window (before the extra filters).
        $payload['meta'] = $meta;
        $payload['options'] = [
            'actions' => (clone $window)->distinct()->orderBy('a.action')->pluck('a.action')->map(fn ($v) => (string) $v)->all(),
            'staff' => $this->staffOptions($window),
        ];

        return $payload;
    }

    /**
     * A problem row: failed / missing / unverifiable, or legacy recorded at or
     * after its device's P5 marker (F2, 1b).
     */
    public static function isProblem(string $result, bool $afterP5Marker): bool
    {
        return in_array($result, self::PROBLEMS, true) || ($result === 'legacy' && $afterP5Marker);
    }

    /** The row was recorded at or after its device first sent auth_v: 1. */
    private static function afterMarker(mixed $createdAt, mixed $authVSeenAt): bool
    {
        if ($createdAt === null || $authVSeenAt === null) {
            return false;
        }

        return Carbon::parse((string) $createdAt)->greaterThanOrEqualTo(Carbon::parse((string) $authVSeenAt));
    }

    /** The SQL form of {@see isProblem()} on `pos_approvals as a`. */
    private function problemCondition(Builder $q): Builder
    {
        return $q->whereIn('a.result', self::PROBLEMS)
            ->orWhere(fn (Builder $q) => $q->where('a.result', 'legacy')->whereExists(fn (Builder $s) => $this->p5Device($s)));
    }

    /**
     * The row's device had already sent auth_v: 1 when the row was recorded
     * (created_at >= pos_devices.auth_v_seen_at).
     */
    private function p5Device(Builder $q): Builder
    {
        return $q->selectRaw('1')->from('pos_devices as pd')
            ->whereColumn('pd.id', 'a.device_id')
            ->whereNotNull('pd.auth_v_seen_at')
            ->whereColumn('a.created_at', '>=', 'pd.auth_v_seen_at');
    }

    /**
     * Everyone who acted or approved in the window, by name.
     *
     * @return list<array{id: int, name: string}>
     */
    private function staffOptions(Builder $window): array
    {
        $ids = (clone $window)->whereNotNull('a.actor_staff_id')->distinct()->pluck('a.actor_staff_id')
            ->merge((clone $window)->whereNotNull('a.approver_staff_id')->distinct()->pluck('a.approver_staff_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        return DB::table('pos_staff')
            ->where('company_id', $this->tenant->requiredId())
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn ($s): array => ['id' => (int) $s->id, 'name' => (string) $s->name])
            ->all();
    }
}
