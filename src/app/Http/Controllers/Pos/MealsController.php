<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Catalogue\SaveMealAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\SaveMealRequest;
use App\Models\Meal;
use App\Support\Catalogue\ComboLinesInput;
use App\Support\Catalogue\MealMains;
use App\Support\MerchantTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * LAUNCH combo add-on (owner decision 4) — the meal setups ("Make it a
 * meal?"):
 *
 *   GET    /api/meals               → every meal
 *   POST   /api/meals               → create a meal
 *   GET    /api/meals/{meal:uuid}   → one meal
 *   PUT    /api/meals/{meal:uuid}   → save the whole meal (lines keep ids)
 *   DELETE /api/meals/{meal:uuid}   → delete it (order lines keep its id)
 *
 * Reads gated on catalogue.view, writes on catalogue.manage.
 */
class MealsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SaveMealAction $save,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);
        $meals = Meal::query()->where('company_id', $this->tenant->requiredId())->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $meals->map(fn (Meal $meal): array => $this->present($meal))->values()->all()]);
    }

    public function show(Request $request, Meal $meal): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueView);
        $this->refuseIfNotInTenant($meal);

        return response()->json(['data' => $this->present($meal)]);
    }

    public function store(SaveMealRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);

        return $this->persist($request, null, 201);
    }

    public function update(SaveMealRequest $request, Meal $meal): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($meal);

        return $this->persist($request, $meal, 200);
    }

    public function destroy(Request $request, Meal $meal): JsonResponse
    {
        $this->ensure($request, MerchantPermission::CatalogueManage);
        $this->refuseIfNotInTenant($meal);
        $meal->delete();
        $this->writeAuditLog->handle(new AuditLogData(
            event: 'catalogue.meal.deleted',
            actorUserId: $request->user()?->getKey(),
            companyId: (int) $meal->company_id,
            auditableType: Meal::class,
            auditableId: $meal->id,
            oldValues: ['name' => $meal->name],
            newValues: [],
        ));

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function persist(SaveMealRequest $request, ?Meal $meal, int $status): JsonResponse
    {
        try {
            $saved = $this->save->handle($meal, $request->validated(), $request->user());
        } catch (QueryException|HttpException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($saved)], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Meal $meal): array
    {
        $categoryIds = $meal->categories()->orderBy('pos_product_categories.id')->pluck('pos_product_categories.id')
            ->map(static fn ($id): int => (int) $id)->all();
        $excluded = $meal->excludedProducts()->get(['pos_products.id', 'pos_products.uuid']);

        return [
            'id' => (int) $meal->id,
            'uuid' => (string) $meal->uuid,
            'name' => $meal->name,
            'name_ar' => $meal->name_ar,
            'meal_price' => (string) $meal->meal_price,
            'status' => $meal->status,
            'on_sale_from' => $meal->on_sale_from?->format('Y-m-d'),
            'on_sale_until' => $meal->on_sale_until?->format('Y-m-d'),
            'sort_order' => (int) $meal->sort_order,
            'category_ids' => $categoryIds,
            'excluded_product_uuids' => $excluded->pluck('uuid')->map(static fn ($uuid): string => (string) $uuid)->values()->all(),
            'mains_count' => count(MealMains::of((int) $meal->company_id, $categoryIds,
                $excluded->pluck('id')->map(static fn ($id): int => (int) $id)->all())),
            'lines' => ComboLinesInput::present(['meal_id' => (int) $meal->id]),
        ];
    }

    private function refuseIfNotInTenant(Meal $meal): void
    {
        if ((int) $meal->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }
}
