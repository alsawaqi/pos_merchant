<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Support\MerchantTenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase B — Comp Report (Additions §1.2: "new addition to Blueprint
 * §5.11 reporting").
 *
 *   - Total comp value in window + comp count + comped-order count
 *   - By reason (snapshot — renamed/deleted reasons still read)
 *   - By branch
 *   - By the STAFF WHO APPROVED — LAUNCH-P5 B3 fix: only an approval the
 *     server checked counts. Before P5 every device sent no approver, so
 *     pos_order_comps.approved_by_pos_staff_id held the CASHIER; such rows
 *     (and any comp whose approval failed or is missing) now land in one
 *     "not verified" bucket (staff_id null, verified false) instead of
 *     crediting the cashier. A comp is credited to its approver when
 *     pos_approvals has a row for the same order (subject_uuid = the
 *     order uuid) and action ('comp', or 'gift' for gift rows) with result
 *     verified / position_ok whose approver (or, for position_ok, actor) is
 *     the stored approved_by_pos_staff_id.
 *   - Recent comps drill-down (newest 25, with the order reference)
 *
 * Driven by pos_order_comps — the comp-application records the
 * pos_api order.create pipeline writes — joined to pos_orders for
 * the PAID + window + branch scope. A comp is money given away on a
 * sale that still settled, so unlike voids the parent order remains
 * paid; inventory deducted as sold (the food went to the customer).
 *
 * P-F5 — GIFT rows (is_gift = true: a line given away whole, no
 * reason, no cap) are split OUT of the reason-coded analysis into
 * their own `gifts` bucket (count + total + gifted-order count) so
 * they never pollute the manager-comp reason breakdown. The headline
 * still totals ALL write-offs (it mirrors pos_orders.comp_total);
 * by-branch/by-staff/recent keep all rows too (gift rows read as
 * the fixed 'Gift' snapshot there).
 */
final readonly class CompReportAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ReportFilter $filter): array
    {
        $companyId = $this->tenant->requiredId();
        $branchScope = $filter->branchScope();

        $base = DB::table('pos_order_comps as oc')
            ->join('pos_orders', 'pos_orders.id', '=', 'oc.order_id')
            ->where('oc.company_id', $companyId)
            ->where('pos_orders.status', OrderStatus::Paid->value)
            ->whereBetween('pos_orders.opened_at', [$filter->dateFrom, $filter->dateTo]);
        if ($branchScope !== null) {
            $base->whereIn('pos_orders.branch_id', $branchScope);
        }

        // ---- Headline ----
        $headline = (clone $base)
            ->selectRaw('
                COALESCE(SUM(oc.amount), 0) AS total_value,
                COUNT(*) AS comp_count,
                COUNT(DISTINCT oc.order_id) AS comped_order_count
            ')
            ->first();

        // ---- Gifts (P-F5) — their own bucket, never mixed into reasons ----
        $gifts = (clone $base)
            ->where('oc.is_gift', true)
            ->selectRaw('
                COALESCE(SUM(oc.amount), 0) AS total_value,
                COUNT(*) AS gift_count,
                COUNT(DISTINCT oc.order_id) AS gifted_order_count
            ')
            ->first();

        // ---- By reason (snapshot) — manager comps only ----
        $byReason = (clone $base)
            ->where('oc.is_gift', false)
            ->selectRaw('
                oc.reason_code_snapshot AS code,
                oc.reason_name_snapshot AS name,
                COALESCE(SUM(oc.amount), 0) AS value,
                COUNT(*) AS comp_count
            ')
            ->groupBy('oc.reason_code_snapshot', 'oc.reason_name_snapshot')
            ->orderByDesc('value')
            ->get()
            ->map(static fn ($r): array => [
                'code' => (string) $r->code,
                'name' => (string) $r->name,
                'value' => number_format((float) $r->value, 3, '.', ''),
                'comp_count' => (int) $r->comp_count,
            ])->all();

        // ---- By branch ----
        $byBranch = (clone $base)
            ->join('pos_branches', 'pos_branches.id', '=', 'oc.branch_id')
            ->selectRaw('
                oc.branch_id AS branch_id,
                pos_branches.name AS branch_name,
                COALESCE(SUM(oc.amount), 0) AS value,
                COUNT(*) AS comp_count
            ')
            ->groupBy('oc.branch_id', 'pos_branches.name')
            ->orderByDesc('value')
            ->get()
            ->map(static fn ($r): array => [
                'branch_id' => (int) $r->branch_id,
                'branch_name' => (string) $r->branch_name,
                'value' => number_format((float) $r->value, 3, '.', ''),
                'comp_count' => (int) $r->comp_count,
            ])->all();

        // ---- By approving staff (LAUNCH-P5: checked approvals only) ----
        $byStaff = (clone $base)
            ->whereExists(fn (Builder $q) => $this->checkedApproval($q))
            ->join('pos_staff', 'pos_staff.id', '=', 'oc.approved_by_pos_staff_id')
            ->selectRaw('
                oc.approved_by_pos_staff_id AS staff_id,
                pos_staff.name AS staff_name,
                COALESCE(SUM(oc.amount), 0) AS value,
                COUNT(*) AS comp_count
            ')
            ->groupBy('oc.approved_by_pos_staff_id', 'pos_staff.name')
            ->orderByDesc('value')
            ->get()
            ->map(static fn ($r): array => [
                'staff_id' => (int) $r->staff_id,
                'staff_name' => (string) $r->staff_name,
                'verified' => true,
                'value' => number_format((float) $r->value, 3, '.', ''),
                'comp_count' => (int) $r->comp_count,
            ])->all();

        $unverified = (clone $base)
            ->whereNotExists(fn (Builder $q) => $this->checkedApproval($q))
            ->selectRaw('COALESCE(SUM(oc.amount), 0) AS value, COUNT(*) AS comp_count')
            ->first();
        if ((int) ($unverified?->comp_count ?? 0) > 0) {
            $byStaff[] = [
                'staff_id' => null,
                'staff_name' => null,
                'verified' => false,
                'value' => number_format((float) $unverified->value, 3, '.', ''),
                'comp_count' => (int) $unverified->comp_count,
            ];
        }

        // ---- Recent comps (drill-down) ----
        $recent = (clone $base)
            ->leftJoin('pos_staff as approver', 'approver.id', '=', 'oc.approved_by_pos_staff_id')
            ->select([
                'oc.id AS id',
                'oc.reason_name_snapshot AS reason',
                'oc.amount AS amount',
                'oc.order_item_id AS order_item_id',
                'oc.note AS note',
                'oc.applied_at AS applied_at',
                'pos_orders.uuid AS order_uuid',
                'approver.name AS approver_name',
            ])
            ->selectSub(
                fn (Builder $q) => $this->checkedApproval($q->selectRaw('COUNT(*)')),
                'checked',
            )
            ->orderByDesc('oc.applied_at')
            ->orderByDesc('oc.id')
            ->limit(25)
            ->get()
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'reason' => (string) $r->reason,
                'amount' => number_format((float) $r->amount, 3, '.', ''),
                'scope' => $r->order_item_id !== null ? 'line' : 'order',
                'note' => $r->note !== null ? (string) $r->note : null,
                'applied_at' => $r->applied_at !== null ? (string) $r->applied_at : null,
                'order_uuid' => (string) $r->order_uuid,
                // LAUNCH-P5 — the approver only when the server checked it.
                'approved_by' => (int) $r->checked > 0 && $r->approver_name !== null ? (string) $r->approver_name : null,
                'approval_verified' => (int) $r->checked > 0,
            ])->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $branchScope,
            ],
            'headline' => [
                'total_value' => number_format((float) ($headline?->total_value ?? 0), 3, '.', ''),
                'comp_count' => (int) ($headline?->comp_count ?? 0),
                'comped_order_count' => (int) ($headline?->comped_order_count ?? 0),
            ],
            'gifts' => [
                'total_value' => number_format((float) ($gifts?->total_value ?? 0), 3, '.', ''),
                'gift_count' => (int) ($gifts?->gift_count ?? 0),
                'gifted_order_count' => (int) ($gifts?->gifted_order_count ?? 0),
            ],
            'by_reason' => $byReason,
            'by_branch' => $byBranch,
            'by_staff' => $byStaff,
            'recent' => $recent,
        ];
    }

    /**
     * LAUNCH-P5 — a checked pos_approvals row for this comp row: same company,
     * same order (subject_uuid), action 'comp' ('gift' for a gift row), result
     * verified or position_ok, and its approver (or, for position_ok, its
     * actor) is the comp's stored approver.
     */
    private function checkedApproval(Builder $q): Builder
    {
        return $q->from('pos_approvals as pa')
            ->whereColumn('pa.company_id', 'oc.company_id')
            ->whereColumn('pa.subject_uuid', 'pos_orders.uuid')
            ->whereRaw("pa.action = CASE WHEN oc.is_gift THEN 'gift' ELSE 'comp' END")
            ->whereIn('pa.result', ['verified', 'position_ok'])
            ->whereRaw('COALESCE(pa.approver_staff_id, pa.actor_staff_id) = oc.approved_by_pos_staff_id');
    }
}
