<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\DashboardSummaryAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 7b-7 — merchant Dashboard summary endpoint.
 *
 * One GET that the landing page hits on mount. Gated under
 * reports.view -- every dashboard widget is a small report
 * cluster, so the same gate makes sense.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardSummaryAction $summary,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::ReportsView->value)) {
            abort(403);
        }

        return response()->json(['data' => $this->summary->handle()]);
    }
}
