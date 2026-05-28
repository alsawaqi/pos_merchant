<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\AuditLogReportAction;
use App\Actions\Pos\Reports\CustomerReportAction;
use App\Actions\Pos\Reports\DiscountReportAction;
use App\Actions\Pos\Reports\InventoryConsumptionReportAction;
use App\Actions\Pos\Reports\LossWasteReportAction;
use App\Actions\Pos\Reports\ProductPerformanceReportAction;
use App\Actions\Pos\Reports\RecipeCostReportAction;
use App\Actions\Pos\Reports\RestockPurchasingReportAction;
use App\Actions\Pos\Reports\RoundUpDonationReportAction;
use App\Actions\Pos\Reports\SalesReportAction;
use App\Actions\Pos\Reports\StaffActivityReportAction;
use App\Data\Reports\ReportFilter;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Reports\AuditLogFilterRequest;
use App\Http\Requests\Pos\Reports\ReportFilterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7b — reports endpoints (blueprint §13 Phase 7).
 *
 * Routes follow the blueprint §11.3 pattern:
 *   GET /api/reports/{report_key}            -- run a report
 *
 * Each report key dispatches to its own Action. Adding a new
 * report = adding an Action + a method here. The shared filter
 * pattern (ReportFilter DTO) keeps the controller thin.
 *
 * Permission gating:
 *   reports.view  for every GET
 *   (Phase 7b-1 ships Sales only; later sub-phases add the
 *    other 9 reports + the export action gating)
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly SalesReportAction $salesReport,
        private readonly CustomerReportAction $customerReport,
        private readonly DiscountReportAction $discountReport,
        private readonly ProductPerformanceReportAction $productPerformanceReport,
        private readonly RecipeCostReportAction $recipeCostReport,
        private readonly StaffActivityReportAction $staffActivityReport,
        private readonly InventoryConsumptionReportAction $inventoryConsumptionReport,
        private readonly LossWasteReportAction $lossWasteReport,
        private readonly RestockPurchasingReportAction $restockPurchasingReport,
        private readonly RoundUpDonationReportAction $roundUpDonationReport,
        private readonly AuditLogReportAction $auditLogReport,
    ) {}

    public function sales(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->salesReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function customers(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->customerReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function discounts(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->discountReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function productPerformance(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->productPerformanceReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function recipeCost(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->recipeCostReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function staffActivity(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->staffActivityReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function inventoryConsumption(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->inventoryConsumptionReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function lossWaste(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->lossWasteReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function restockPurchasing(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->restockPurchasingReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    public function roundUpDonation(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->roundUpDonationReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

    /**
     * Audit Log viewer (blueprint §5.12). Gated under the
     * AuditLogView permission (NOT ReportsView) so merchants
     * can grant reporting broadly while keeping the audit log
     * restricted to managers.
     */
    public function auditLog(AuditLogFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::AuditLogView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->auditLogReport->handle($filter, [
            'event' => $request->input('event'),
            'actor_id' => $request->input('actor_id'),
            'page' => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', 50),
        ]);

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
