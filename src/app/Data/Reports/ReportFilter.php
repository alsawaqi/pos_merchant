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
     * P-G5 — $allowedBranchIds is the actor's branch scope
     * ({@see \App\Models\User::allowedBranchIds()}); NULL =
     * unrestricted (today's behavior). For a restricted user the
     * filter is CLAMPED at construction, so every report, the orders
     * list and every export inherit enforcement in one place:
     *
     *   - no branch filter requested → branchIds = the user's scope
     *     (their personal "all branches");
     *   - requested ⊆ scope          → the requested subset;
     *   - any id outside the scope   → 403 (explicit requests are
     *     rejected, never silently shrunk);
     *   - empty scope ([])           → 403 (no branch data at all).
     *
     * FOOTGUN guarded here: the legacy "[] → null" normalization means
     * 'all branches' — a restricted user's clamp must never collapse
     * back to null.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>|null  $allowedBranchIds
     */
    public static function fromArray(array $input, ?array $allowedBranchIds = null): self
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

        if ($allowedBranchIds !== null) {
            if ($allowedBranchIds === []) {
                abort(403, 'Your account has no branch access.');
            }
            if ($branchIds === null) {
                $branchIds = $allowedBranchIds;
            } else {
                foreach ($branchIds as $id) {
                    if (! in_array($id, $allowedBranchIds, true)) {
                        abort(403, 'Your account is restricted to specific branches.');
                    }
                }
            }
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
