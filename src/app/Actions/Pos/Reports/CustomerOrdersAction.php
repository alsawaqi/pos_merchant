<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\MerchantTenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Customer 360 order history (v2 #8) — paginated list of one customer's
 * orders (ALL statuses, newest first), tenant-scoped. Returns the same
 * lean row shape as the company Orders list so the frontend reuses its
 * row type + the order-detail drawer on click. The totals banner sums
 * only PAID grand_total (revenue), separate from the row count.
 */
final readonly class CustomerOrdersAction
{
    private const DEFAULT_PER_PAGE = 20;

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * P-G5 — $branchIds limits the history to the actor's branch scope
     * (NULL = unrestricted): a customer's orders at other branches stay
     * invisible to a branch-restricted user.
     *
     * @param  array{page?: int, per_page?: int}  $extras
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function handle(int $customerId, array $extras = [], ?array $branchIds = null): array
    {
        $companyId = $this->tenant->requiredId();
        $perPage = max(1, min(100, (int) ($extras['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $page = max(1, (int) ($extras['page'] ?? 1));

        $query = Order::query()
            ->with(['branch:id,name'])
            ->withCount('items')
            ->where('company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->where('customer_id', $customerId)
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        // Revenue banner = PAID orders only (void/refunded excluded),
        // computed over the WHOLE filtered set, not just the page.
        $paidTotal = (float) (clone $query)->where('status', OrderStatus::Paid->value)->sum('grand_total');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $rows = collect($paginator->items())->map(static fn (Order $o): array => [
            'id' => (int) $o->id,
            'uuid' => $o->uuid,
            'branch_name' => $o->branch?->name,
            'order_type' => $o->order_type?->value,
            'status' => $o->status?->value,
            'items_count' => (int) $o->items_count,
            'discount_total' => (string) $o->discount_total,
            'grand_total' => (string) $o->grand_total,
            'opened_at' => $o->opened_at?->format('Y-m-d\TH:i:s'),
        ])->all();

        return [
            'totals' => [
                'count' => $paginator->total(),
                'paid_total' => number_format($paidTotal, 3, '.', ''),
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
