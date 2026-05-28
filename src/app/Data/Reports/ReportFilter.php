<?php

declare(strict_types=1);

namespace App\Data\Reports;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Phase 7b — shared filter DTO for every report (blueprint
 * §5.11 "All reports support: filter by date range, filter by
 * branch (single, multi, or 'all')").
 *
 * Construction:
 *   - dateFrom / dateTo: inclusive on both sides; reports query
 *     orders.opened_at BETWEEN [from, to]
 *   - branchIds: NULL = all branches; non-empty array = subset.
 *     Reports auto-apply company_id scoping on top
 *   - consolidated: true means "merge all branches in the result
 *     set"; false means "break down per branch". The §5.11 reports
 *     that support this toggle render the consolidated/per-branch
 *     mode based on this flag
 *
 * Construction is via fromArray() so the controller can pass
 * the validated request payload directly without manual mapping.
 */
final readonly class ReportFilter
{
    /**
     * @param  list<int>|null  $branchIds
     */
    public function __construct(
        public DateTimeInterface $dateFrom,
        public DateTimeInterface $dateTo,
        public ?array $branchIds,
        public bool $consolidated,
    ) {}

    /**
     * Build from a controller payload. Required keys: date_from,
     * date_to. Optional: branch_ids (null or array), consolidated
     * (defaults to true).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $dateFrom = Carbon::parse((string) $input['date_from'])->startOfDay();
        $dateTo = Carbon::parse((string) $input['date_to'])->endOfDay();
        $branchIds = $input['branch_ids'] ?? null;
        if (is_array($branchIds)) {
            $branchIds = array_values(array_map(static fn ($v): int => (int) $v, $branchIds));
            if ($branchIds === []) {
                $branchIds = null;
            }
        } else {
            $branchIds = null;
        }
        $consolidated = (bool) ($input['consolidated'] ?? true);

        return new self($dateFrom, $dateTo, $branchIds, $consolidated);
    }

    /**
     * Branch predicate that callers apply to the query builder:
     * - branchIds=null  → no constraint
     * - branchIds=[..]  → ->whereIn('branch_id', branchIds)
     *
     * Returns the array of branch IDs or NULL for "no restriction".
     *
     * @return list<int>|null
     */
    public function branchScope(): ?array
    {
        return $this->branchIds;
    }
}
