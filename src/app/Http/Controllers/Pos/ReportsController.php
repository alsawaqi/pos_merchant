<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\AuditLogReportAction;
use App\Actions\Pos\Reports\CustomerReportAction;
use App\Actions\Pos\Reports\DiscountReportAction;
use App\Actions\Pos\Reports\InventoryConsumptionReportAction;
use App\Actions\Pos\Reports\LossWasteReportAction;
use App\Actions\Pos\Reports\PayoutBreakdownReportAction;
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
use App\Support\ReportCsvExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        private readonly PayoutBreakdownReportAction $payoutBreakdownReport,
    ) {}

    public function payouts(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->payoutBreakdownReport->handle($filter);

        return response()->json(['data' => $payload]);
    }

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

    /**
     * GET /api/reports/{report}/export
     *
     * CSV export of any analytics report. Runs the same Action as the JSON
     * endpoint, then flattens the multi-section payload to CSV. Gated on
     * reports.export — distinct from reports.view, so a view-only role can read
     * a report on-screen but not download it. The audit log is intentionally
     * excluded (it's a separate, AuditLogView-gated viewer with pagination).
     *
     * Fetched with Accept: application/json like the rest of the API (the group's
     * RequireJsonRequest middleware 406s otherwise); the SPA reads the returned
     * text/csv body as a blob to trigger the download.
     */
    public function export(ReportFilterRequest $request, string $report, ReportCsvExporter $exporter): Response
    {
        $this->ensure($request, MerchantPermission::ReportsExport);

        $reports = [
            'sales' => $this->salesReport,
            'customers' => $this->customerReport,
            'discounts' => $this->discountReport,
            'product-performance' => $this->productPerformanceReport,
            'recipe-cost' => $this->recipeCostReport,
            'staff-activity' => $this->staffActivityReport,
            'inventory-consumption' => $this->inventoryConsumptionReport,
            'loss-waste' => $this->lossWasteReport,
            'restock-purchasing' => $this->restockPurchasingReport,
            'round-up-donation' => $this->roundUpDonationReport,
        ];

        if (! array_key_exists($report, $reports)) {
            abort(404);
        }

        $filter = ReportFilter::fromArray($request->validated());
        $csv = $exporter->toCsv($reports[$report]->handle($filter));

        $validated = $request->validated();
        $filename = sprintf('%s-report_%s_to_%s.csv', $report, $validated['date_from'], $validated['date_to']);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}
