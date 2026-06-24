<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Actions\Pos\Reports\Support\SaleCommissionStatus;
use App\Data\Reports\ReportFilter;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\MerchantTenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Merchant "Sales / Orders" list -- a paginated, date-filterable feed of
 * the company's actual orders (not the §5.11 aggregate Sales report). Same
 * ReportFilter shape (date window + optional branch scope) as the reports
 * cluster, plus an optional status filter + page/per_page. Tenant-scoped to
 * company_id; cross-tenant orders are never returnable. A `totals` block
 * sums the WHOLE filtered set (every page), not just the current page.
 */
final readonly class OrdersListAction
{
    private const DEFAULT_PER_PAGE = 50;

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{status?: string|null, page?: int, per_page?: int}  $extras
     * @return array<string, mixed>
     */
    public function handle(ReportFilter $filter, array $extras = []): array
    {
        $companyId = $this->tenant->requiredId();
        $branchScope = $filter->branchScope();
        $status = isset($extras['status']) && $extras['status'] !== '' && $extras['status'] !== null
            ? (string) $extras['status'] : null;
        $perPage = max(1, min(200, (int) ($extras['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $page = max(1, (int) ($extras['page'] ?? 1));

        $query = Order::query()
            ->with(['branch:id,name', 'customer:id,name'])
            ->withCount('items')
            ->where('company_id', $companyId)
            ->whereBetween('opened_at', [$filter->dateFrom, $filter->dateTo])
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        if ($branchScope !== null) {
            $query->whereIn('branch_id', $branchScope);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }

        // Totals across the ENTIRE filtered set (before pagination).
        // P-G7 — pending-verification deliveries stay visible in the rows
        // but are NOT revenue until confirmed, so the banner excludes them —
        // UNLESS the user explicitly filtered for them (then the banner is
        // the outstanding-with-providers total, not revenue; excluding would
        // contradict the rows with a 0.000 over visible money).
        $totalsQuery = clone $query;
        if ($status !== OrderStatus::PendingVerification->value) {
            $totalsQuery->where('status', '!=', OrderStatus::PendingVerification->value);
        }
        $grandTotal = (float) $totalsQuery->sum('grand_total');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $orders = collect($paginator->items());
        // Per-sale commission breakdown + reconciliation/payout status
        // (settled-aware; final only once the payout is paid). Batched — one
        // ledger read for the whole page.
        $commissionByOrder = SaleCommissionStatus::forOrders(
            $companyId,
            $orders->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        $rows = $orders->map(static function (Order $o) use ($commissionByOrder): array {
            $commission = $commissionByOrder[(int) $o->id]
                ?? SaleCommissionStatus::none((string) $o->grand_total);

            return [
                'id' => (int) $o->id,
                'uuid' => $o->uuid,
                // P-F8 — the printed receipt number; null for unnumbered
                // orders (the UI falls back to the short uuid).
                'receipt_number' => $o->receipt_number,
                'branch_id' => (int) $o->branch_id,
                'branch_name' => $o->branch?->name,
                'order_type' => $o->order_type?->value,
                'status' => $o->status?->value,
                'source' => $o->source?->value,
                'customer_name' => $o->customer?->name,
                'plate_number' => $o->plate_number,
                'items_count' => (int) $o->items_count,
                'subtotal' => (string) $o->subtotal,
                'discount_total' => (string) $o->discount_total,
                'tax_total' => (string) $o->tax_total,
                'grand_total' => (string) $o->grand_total,
                'opened_at' => $o->opened_at?->format('Y-m-d\TH:i:s'),
                'closed_at' => $o->closed_at?->format('Y-m-d\TH:i:s'),
                // Commission + payout status (settled-aware; final once paid).
                'admin_commission' => $commission['admin_commission'],
                'bank_commission' => $commission['bank_commission'],
                'total_commission' => $commission['total_commission'],
                'merchant_net' => $commission['merchant_net'],
                'commission_status' => $commission['commission_status'],
                'is_finalized' => $commission['is_finalized'],
                'payout_date' => $commission['payout_date'],
            ];
        })->all();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'branch_ids' => $branchScope,
                'status' => $status,
            ],
            'totals' => [
                'count' => $paginator->total(),
                'grand_total' => number_format($grandTotal, 3, '.', ''),
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
