<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Expenses\CreateExpenseCategoryAction;
use App\Actions\Pos\Expenses\DeleteExpenseCategoryAction;
use App\Actions\Pos\Expenses\EnsureDefaultExpenseCategoriesAction;
use App\Actions\Pos\Expenses\UpdateExpenseCategoryAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Expenses\CreateExpenseCategoryRequest;
use App\Http\Requests\Pos\Expenses\UpdateExpenseCategoryRequest;
use App\Http\Resources\Pos\Expenses\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Company-level expense categories CRUD (merchant settings; blueprint §5.10).
 *
 *   GET    /api/expense-categories
 *   POST   /api/expense-categories
 *   PATCH  /api/expense-categories/{cat:uuid}
 *   DELETE /api/expense-categories/{cat:uuid}
 *
 * Permission gating uses the Expenses keys (ExpensesView for read,
 * ExpensesManage for writes). The index seeds the six defaults the first time a
 * company opens the screen so the list is never empty.
 */
class ExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateExpenseCategoryAction $createCategory,
        private readonly UpdateExpenseCategoryAction $updateCategory,
        private readonly DeleteExpenseCategoryAction $deleteCategory,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::ExpensesView);

        app(EnsureDefaultExpenseCategoriesAction::class)->handle($this->tenant->requiredId());

        $categories = ExpenseCategory::query()
            ->where('company_id', $this->tenant->requiredId())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ExpenseCategoryResource::collection($categories);
    }

    public function store(CreateExpenseCategoryRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ExpensesManage);

        try {
            $category = $this->createCategory->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new ExpenseCategoryResource($category))->resolve($request),
        ], 201);
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $category): ExpenseCategoryResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::ExpensesManage);
        $this->refuseIfNotInTenant($category);

        try {
            $updated = $this->updateCategory->handle($category, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ExpenseCategoryResource::make($updated);
    }

    public function destroy(Request $request, ExpenseCategory $category): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ExpensesManage);
        $this->refuseIfNotInTenant($category);

        $this->deleteCategory->handle($category, $request->user());

        return response()->json(['data' => null], 204);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(ExpenseCategory $category): void
    {
        if ((int) $category->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
