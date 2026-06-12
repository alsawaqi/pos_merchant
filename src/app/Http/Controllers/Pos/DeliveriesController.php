<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Deliveries\ConfirmDeliveryOrdersAction;
use App\Enums\MerchantPermission;
use App\Enums\OrderStatus;
use App\Http\Requests\Pos\Deliveries\AdjustDeliveryRequest;
use App\Http\Requests\Pos\Deliveries\ConfirmDeliveriesRequest;
use App\Models\Order;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * P-G7 — the Deliveries settlement page (deliveries.manage-gated).
 *
 *   GET  /api/deliveries                      pending (default) or confirmed
 *                                             delivery orders, paginated;
 *                                             pending carries whole-set totals
 *                                             (punched + expected) per the
 *                                             P-F7 queue precedent.
 *   POST /api/deliveries/confirm              bulk-confirm at the expected
 *                                             payout (the statement matched).
 *   POST /api/deliveries/{order:uuid}/adjust  confirm ONE order at the amount
 *                                             actually received; variance
 *                                             recorded.
 *
 * Confirmation flips the order to paid and re-dates it to the confirmation
 * moment — only then does it count as revenue anywhere. F5: lists shrink to
 * the actor's branch scope; decisions 403 on any out-of-scope order.
 */
class DeliveriesController
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly ConfirmDeliveryOrdersAction $confirm,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::DeliveriesManage);

        $companyId = $this->tenant->requiredId();
        $status = $request->query('status', 'pending') === 'confirmed' ? 'confirmed' : 'pending';
        $perPage = min(max((int) $request->query('per_page', '25'), 1), 100);

        // F5 — the list silently shrinks to the actor's scope; an explicit
        // out-of-scope branch filter 403s inside constrain().
        $requestedBranch = $request->filled('branch_id') ? [(int) $request->query('branch_id')] : null;
        $branchIds = BranchScope::constrain($request->user(), $requestedBranch);

        $base = Order::query()
            ->with(['branch:id,name'])
            ->where('company_id', $companyId)
            ->whereNotNull('delivery_provider_id')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds));

        if ($status === 'pending') {
            $query = (clone $base)
                ->where('status', OrderStatus::PendingVerification->value)
                ->orderBy('delivery_punched_at')
                ->orderBy('id');
        } else {
            $query = (clone $base)
                ->where('status', OrderStatus::Paid->value)
                ->whereNotNull('delivery_confirmed_at')
                ->orderByDesc('delivery_confirmed_at')
                ->orderByDesc('id');
        }

        // Whole-set totals (every page), the P-F7 queue precedent.
        $totals = [
            'count' => (clone $query)->count(),
            'punched_total' => number_format((float) (clone $query)->sum('grand_total'), 3, '.', ''),
            'expected_total' => number_format((float) (clone $query)->sum('delivery_expected_payout'), 3, '.', ''),
        ];
        if ($status === 'confirmed') {
            $totals['received_total'] = number_format((float) (clone $query)->sum('delivery_received_amount'), 3, '.', '');
            $totals['variance_total'] = number_format((float) (clone $query)->sum('delivery_variance'), 3, '.', '');
        }

        $page = $query->paginate($perPage)->through(fn (Order $o): array => $this->map($o));

        return response()->json([
            'data' => $page->items(),
            'totals' => $totals,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function confirm(ConfirmDeliveriesRequest $request): JsonResponse
    {
        try {
            $result = $this->confirm->handle(
                array_map(intval(...), $request->validated('order_ids')),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function adjust(AdjustDeliveryRequest $request, Order $order): JsonResponse
    {
        // Tenant 404 BEFORE the scope 403 (the OrdersController@show rule).
        if ((int) $order->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }

        try {
            $result = $this->confirm->handle(
                [(int) $order->id],
                $request->user(),
                (string) $request->validated('received_amount'),
            );
        } catch (RuntimeException $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(Order $order): array
    {
        return [
            'id' => (int) $order->id,
            'uuid' => $order->uuid,
            'branch_id' => (int) $order->branch_id,
            'branch_name' => $order->branch?->name,
            'receipt_number' => $order->receipt_number,
            'provider_id' => $order->delivery_provider_id !== null ? (int) $order->delivery_provider_id : null,
            'provider_name' => $order->delivery_provider_name,
            'reference' => $order->delivery_reference,
            'customer_phone' => $order->delivery_customer_phone,
            'driver_phone' => $order->delivery_driver_phone,
            'commission_percent' => (string) $order->delivery_commission_percent,
            'grand_total' => (string) $order->grand_total,
            'expected_payout' => (string) $order->delivery_expected_payout,
            'received_amount' => $order->delivery_received_amount !== null ? (string) $order->delivery_received_amount : null,
            'variance' => $order->delivery_variance !== null ? (string) $order->delivery_variance : null,
            'punched_at' => $order->delivery_punched_at?->toIso8601String(),
            'confirmed_at' => $order->delivery_confirmed_at?->toIso8601String(),
            'status' => $order->status?->value,
        ];
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}
