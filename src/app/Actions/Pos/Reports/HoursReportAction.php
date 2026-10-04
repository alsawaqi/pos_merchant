<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Support\BusinessTime;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 B4 — Hours report, from clock in / clock out
 * (pos_staff_attendance), per person and per business day (Muscat), with
 * totals.
 *
 * Days and the date window are Muscat days (pos.business_timezone): a
 * clock-in at 23:30 Muscat counts on that Muscat date, whatever its UTC
 * date. A record without a clock-out:
 *   - still open and less than 16 hours old → "open" (still at work);
 *   - older than 16 hours, or flagged by pos_api → "no clock-out", which
 *     the page highlights. Neither counts towards the hours.
 * Corrections are made in the portal (staff.attendance.manage, with a
 * reason, audited) and the row then shows who edited it and why.
 */
final readonly class HoursReportAction
{
    public const NO_CLOCK_OUT_AFTER_HOURS = 16;

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{staff_id?: int}  $criteria
     * @return array<string, mixed>
     */
    public function handle(ReportFilter $filter, array $criteria = [], bool $forExport = false): array
    {
        $companyId = $this->tenant->requiredId();
        $branchScope = $filter->branchScope();
        $fromDate = $filter->dateFrom->format('Y-m-d');
        $toDate = $filter->dateTo->format('Y-m-d');
        [$from, $to] = BusinessTime::window($fromDate, $toDate);

        $window = DB::table('pos_staff_attendance as a')
            ->where('a.company_id', $companyId)
            ->whereBetween('a.clock_in_at', [$from, $to]);
        if ($branchScope !== null) {
            $window->whereIn('a.branch_id', $branchScope);
        }

        $query = (clone $window)
            ->join('pos_staff as s', 's.id', '=', 'a.staff_id')
            ->join('pos_branches as b', 'b.id', '=', 'a.branch_id')
            ->leftJoin('pos_users as u', 'u.id', '=', 'a.edited_by_user_id')
            ->select([
                'a.uuid', 'a.staff_id', 's.name AS staff_name', 'a.branch_id', 'b.name AS branch_name',
                'a.clock_in_at', 'a.clock_out_at', 'a.source', 'a.flags', 'a.edit_reason', 'u.name AS edited_by',
            ])
            ->orderBy('s.name')
            ->orderBy('a.clock_in_at');
        if (isset($criteria['staff_id'])) {
            $query->where('a.staff_id', $criteria['staff_id']);
        }

        $staleBefore = Carbon::now()->subHours(self::NO_CLOCK_OUT_AFTER_HOURS);
        $rows = $query->get()->map(static function ($r) use ($staleBefore): array {
            $in = Carbon::parse($r->clock_in_at);
            $out = $r->clock_out_at !== null ? Carbon::parse($r->clock_out_at) : null;
            $flags = self::flags($r->flags);
            $noClockOut = $out === null && ($in->lessThan($staleBefore) || in_array('no_clock_out', $flags, true));

            return [
                'uuid' => (string) $r->uuid,
                'staff_id' => (int) $r->staff_id,
                'staff_name' => (string) $r->staff_name,
                'branch_id' => (int) $r->branch_id,
                'branch_name' => (string) $r->branch_name,
                'day' => BusinessTime::day($in),
                'clock_in_at' => $in->toIso8601String(),
                'clock_out_at' => $out?->toIso8601String(),
                'clock_in_local' => BusinessTime::local($in),
                'clock_out_local' => BusinessTime::local($out),
                'hours' => $out !== null ? round($in->diffInSeconds($out) / 3600, 2) : null,
                'open' => $out === null && ! $noClockOut,
                'no_clock_out' => $noClockOut,
                'flags' => $flags,
                'source' => (string) $r->source,
                'edited' => $r->edit_reason !== null,
                'edit_reason' => $r->edit_reason !== null ? (string) $r->edit_reason : null,
                'edited_by' => $r->edited_by !== null ? (string) $r->edited_by : null,
            ];
        })->all();

        // ---- Per person and day, and per person ----
        $days = [];
        $people = [];
        foreach ($rows as $row) {
            $dayKey = $row['staff_id'].'|'.$row['day'];
            $days[$dayKey] ??= ['staff_id' => $row['staff_id'], 'staff_name' => $row['staff_name'], 'day' => $row['day'], 'records' => 0, 'hours' => 0.0, 'no_clock_out' => false];
            $days[$dayKey]['records']++;
            $days[$dayKey]['hours'] += $row['hours'] ?? 0.0;
            $days[$dayKey]['no_clock_out'] = $days[$dayKey]['no_clock_out'] || $row['no_clock_out'];

            $people[$row['staff_id']] ??= ['staff_id' => $row['staff_id'], 'staff_name' => $row['staff_name'], 'records' => 0, 'days' => 0, 'hours' => 0.0, 'no_clock_out' => 0];
            $people[$row['staff_id']]['records']++;
            $people[$row['staff_id']]['hours'] += $row['hours'] ?? 0.0;
            $people[$row['staff_id']]['no_clock_out'] += $row['no_clock_out'] ? 1 : 0;
        }
        foreach ($days as $day) {
            $people[$day['staff_id']]['days']++;
        }
        $days = array_values(array_map(static fn (array $d): array => [...$d, 'hours' => round($d['hours'], 2)], $days));
        $people = array_values(array_map(static fn (array $p): array => [...$p, 'hours' => round($p['hours'], 2)], $people));

        $payload = [
            'window' => [
                'from' => $fromDate,
                'to' => $toDate,
                'timezone' => BusinessTime::timezone(),
                'branch_ids' => $branchScope,
                'staff_id' => $criteria['staff_id'] ?? null,
            ],
            'summary' => [
                'people' => count($people),
                'records' => count($rows),
                'total_hours' => round(array_sum(array_column($people, 'hours')), 2),
                'no_clock_out' => count(array_filter($rows, static fn (array $r): bool => $r['no_clock_out'])),
                'open' => count(array_filter($rows, static fn (array $r): bool => $r['open'])),
            ],
            'people' => $people,
            'days' => $days,
            'rows' => $rows,
        ];

        if (! $forExport) {
            $payload['options'] = [
                'staff' => (clone $window)->join('pos_staff as s', 's.id', '=', 'a.staff_id')
                    ->select('s.id', 's.name')->distinct()->orderBy('s.name')->get()
                    ->map(static fn ($s): array => ['id' => (int) $s->id, 'name' => (string) $s->name])->all(),
            ];
        }

        return $payload;
    }

    /**
     * pos_api's flags as a list of names (a JSON list of strings, or an
     * object of name → truthy).
     *
     * @return list<string>
     */
    public static function flags(mixed $raw): array
    {
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($value)) {
            return [];
        }
        if (array_is_list($value)) {
            return array_values(array_filter(array_map(static fn ($v): string => is_string($v) ? $v : '', $value)));
        }

        return array_values(array_map('strval', array_keys(array_filter($value))));
    }
}
