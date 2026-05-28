<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\SalesReportAction;
use App\Data\Reports\ReportFilter;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
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
    ) {}

    public function sales(ReportFilterRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);

        $filter = ReportFilter::fromArray($request->validated());
        $payload = $this->salesReport->handle($filter);

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
