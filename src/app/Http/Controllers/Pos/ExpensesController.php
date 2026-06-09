<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Expenses\LogExpenseAction;
use App\Actions\Pos\Expenses\RejectExpenseAction;
use App\Actions\Pos\Expenses\ReviewExpenseAction;
use App\Enums\ExpenseStatus;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Expenses\LogExpenseRequest;
use App\Http\Requests\Pos\Expenses\RejectExpenseRequest;
use App\Http\Requests\Pos\Expenses\ReviewExpenseRequest;
use App\Http\Resources\Pos\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Phase 6 backfill — Expenses (blueprint §5.10).
 *
 *   GET  /api/expenses                              review queue (filters)
 *   POST /api/expenses                              log (back-office path)
 *   POST /api/expenses/{expense:uuid}/review        approve + optional note
 *   POST /api/expenses/{expense:uuid}/reject        reject with reason
 *
 * Permission gating:
 *   expenses.view   for the list
 *   expenses.manage for log / review / reject
 *
 * The POS device sync feed (Phase 8) is the OTHER writer of this
 * table; it lands rows in the `recorded` state for the merchant
 * to review here.
 */
class ExpensesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly LogExpenseAction $log,
        private readonly ReviewExpenseAction $review,
        private readonly RejectExpenseAction $reject,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::ExpensesView);

        $query = Expense::query()
            ->where('company_id', $this->tenant->requiredId())
            ->with(['branch', 'loggedByStaff', 'loggedByUser', 'reviewedBy'])
            ->orderByDesc('logged_at')
            ->orderByDesc('id');

        // status filter — fail-closed on an unknown value.
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            if (in_array($status, ExpenseStatus::values(), true)) {
                $query->where('status', $status);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // category filter — by per-company key (v2 #7). An unknown key simply
        // matches no rows within the tenant scope (effectively fail-closed).
        $category = $request->query('category');
        if (is_string($category) && $category !== '') {
            $query->where('category', $category);
        }

        $branchId = $request->query('branch_id');
        if (is_numeric($branchId)) {
            $query->where('branch_id', (int) $branchId);
        }

        $dateFrom = $request->query('date_from');
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->where('logged_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        $dateTo = $request->query('date_to');
        if (is_string($dateTo) && $dateTo !== '') {
            $query->where('logged_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', '25')));

        return ExpenseResource::collection($query->paginate($perPage));
    }

    public function store(LogExpenseRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ExpensesManage);

        try {
            $expense = $this->log->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $expense->load(['branch', 'loggedByUser']);

        return response()->json([
            'data' => (new ExpenseResource($expense))->resolve($request),
        ], 201);
    }

    public function review(ReviewExpenseRequest $request, Expense $expense): ExpenseResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::ExpensesManage);
        $this->refuseIfNotInTenant($expense);

        try {
            $updated = $this->review->handle(
                $expense,
                $request->user(),
                $request->validated()['review_note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load(['branch', 'loggedByStaff', 'loggedByUser', 'reviewedBy']);

        return ExpenseResource::make($updated);
    }

    public function reject(RejectExpenseRequest $request, Expense $expense): ExpenseResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::ExpensesManage);
        $this->refuseIfNotInTenant($expense);

        try {
            $updated = $this->reject->handle(
                $expense,
                $request->user(),
                $request->validated()['review_note'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load(['branch', 'loggedByStaff', 'loggedByUser', 'reviewedBy']);

        return ExpenseResource::make($updated);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Expense $expense): void
    {
        if ((int) $expense->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
