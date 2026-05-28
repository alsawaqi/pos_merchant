<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Models\AuditLog;
use App\Support\MerchantTenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Phase 7b — Audit Log Viewer (blueprint §5.12).
 *
 * Not a report in the §5.11 sense -- there is no headline number
 * or aggregate chart, just a paginated, filterable feed of the
 * tenant's pos_audit_logs rows. We host it under the reports
 * endpoint cluster because:
 *
 *   - It shares the same ReportFilter shape (date window +
 *     optional branch scope)
 *   - The blueprint puts both under "Phase 7 -- Reports & Audit"
 *   - The UI dashboard hits both from the same nav
 *
 * Pagination: 50 rows per page by default. The viewer is
 * scoped to the tenant via company_id; cross-tenant rows are
 * never returnable through this Action.
 *
 * Additional optional filters layered on top of ReportFilter:
 *   - event:    string match on AuditLog.event
 *   - actor_id: scope to a specific portal user's actions
 */
final readonly class AuditLogReportAction
{
    private const DEFAULT_PER_PAGE = 50;

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{event?: string|null, actor_id?: int|null, page?: int, per_page?: int}  $extras
     * @return array<string, mixed>
     */
    public function handle(ReportFilter $filter, array $extras = []): array
    {
        $companyId = $this->tenant->requiredId();
        $branchScope = $filter->branchScope();
        $event = isset($extras['event']) && $extras['event'] !== '' ? (string) $extras['event'] : null;
        $actorId = isset($extras['actor_id']) && $extras['actor_id'] !== null ? (int) $extras['actor_id'] : null;
        $perPage = max(1, min(200, (int) ($extras['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $page = max(1, (int) ($extras['page'] ?? 1));

        $query = AuditLog::query()
            ->with(['actor:id,name,email'])
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$filter->dateFrom, $filter->dateTo])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($branchScope !== null) {
            $query->whereIn('branch_id', $branchScope);
        }
        if ($event !== null) {
            $query->where('event', $event);
        }
        if ($actorId !== null) {
            $query->where('actor_user_id', $actorId);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $rows = collect($paginator->items())->map(static fn (AuditLog $row): array => [
            'id' => (int) $row->id,
            'event' => (string) $row->event,
            'actor_id' => $row->actor_user_id !== null ? (int) $row->actor_user_id : null,
            'actor_name' => $row->actor?->name,
            'actor_email' => $row->actor?->email,
            'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
            'auditable_type' => $row->auditable_type,
            'auditable_id' => $row->auditable_id !== null ? (int) $row->auditable_id : null,
            'ip_address' => $row->ip_address,
            'old_values' => $row->old_values,
            'new_values' => $row->new_values,
            'metadata' => $row->metadata,
            'created_at' => $row->created_at?->format('Y-m-d\TH:i:s'),
        ])->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'branch_ids' => $branchScope,
                'event' => $event,
                'actor_id' => $actorId,
            ],
            'rows' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
