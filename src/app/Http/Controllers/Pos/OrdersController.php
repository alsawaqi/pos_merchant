<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\OrderDetailAction;
use App\Actions\Pos\Reports\OrdersListAction;
use App\Data\Reports\ReportFilter;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Reports\ReportFilterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant Sales / Orders list.
 *
 *   GET /api/orders   -> paginated, date-filterable list of the company's
 *                        own orders (their branches). reports.view-gated,
 *                        tenant-scoped via MerchantTenantContext.
 *
 * Reuses ReportFilterRequest for the date + branch validation; the extra
 * status / page / per_page come off the query string and are clamped in
 * the action.
 */
class OrdersController extends Controller
{
    public function __construct(
        private readonly OrdersListAction $ordersList,
        private readonly OrderDetailAction $orderDetail,
    ) {}

    public function index(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated(), $request->user()?->allowedBranchIds());
        $payload = $this->ordersList->handle($filter, [
            'status' => $request->query('status'),
            'page' => (int) $request->query('page', '1'),
            'per_page' => (int) $request->query('per_page', '50'),
        ]);

        return response()->json(['data' => $payload]);
    }

    /**
     * Full detail for one order (v2 #2). uuid-keyed, tenant-scoped:
     * an unknown / cross-tenant uuid is a 404, never a leak.
     */
    public function show(Request $request, string $order): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $payload = $this->orderDetail->handle($order);
        if ($payload === null) {
            abort(404);
        }

        // P-G5 — the order is in-tenant (404 above otherwise); an order
        // at a branch outside the user's scope is an explicit 403.
        \App\Support\BranchScope::ensureBranch($request->user(), $payload['order']['branch']['id'] ?? null);

        return response()->json(['data' => $payload]);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}
