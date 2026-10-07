<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Costs\MarkPriceAlertSeenAction;
use App\Actions\Pos\Costs\SetCostSettingsAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Support\Costs\CostSettings;
use App\Support\Costs\FoodCost;
use App\Support\Costs\PriceAlerts;
use App\Support\Costs\PriceHistory;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * LAUNCH costs & allergens add-on (tester calls 1 and 2).
 *
 *   GET  /api/settings/costs                  the threshold + company target
 *        (catalogue.view, inventory.view or reports.view)
 *   PUT  /api/settings/costs                  {price_alert_threshold_percent}
 *        needs inventory.manage; {target_food_cost_percent} catalogue.manage
 *   GET  /api/ingredients/{uuid}/price-history  inventory.view (the same
 *        purchase prices the goods-received notes show)
 *   GET  /api/price-alerts?days=30&seen=unseen|all   reports.view
 *   POST /api/price-alerts/{line}/seen        inventory.manage (audited)
 *   GET  /api/food-costs?over=1               every dish's food cost %
 *        (products, combos, meals with each main), reports.view
 *
 * Every read is the merchant's own.
 */
class CostsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
    ) {}

    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! ($user->can(MerchantPermission::CatalogueView->value) || $user->can(MerchantPermission::InventoryView->value)
            || $user->can(MerchantPermission::ReportsView->value))) {
            abort(403);
        }

        return response()->json(['data' => SetCostSettingsAction::current($this->tenant->requiredId())]);
    }

    public function updateSettings(Request $request, SetCostSettingsAction $action): JsonResponse
    {
        if ($request->has('price_alert_threshold_percent')) {
            $this->ensure($request, MerchantPermission::InventoryManage);
        }
        if ($request->has('target_food_cost_percent')) {
            $this->ensure($request, MerchantPermission::CatalogueManage);
        }
        $data = $request->validate([
            'price_alert_threshold_percent' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:1000', 'decimal:0,2'],
            'target_food_cost_percent' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
        ]);
        if ($data === []) {
            return response()->json(['message' => 'Nothing to change.', 'errors' => ['target_food_cost_percent' => ['Nothing to change.']]], 422);
        }

        return response()->json(['data' => $action->handle($data, $request->user())]);
    }

    public function priceHistory(Request $request, Ingredient $ingredient): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $companyId = $this->tenant->requiredId();
        if ((int) $ingredient->company_id !== $companyId) {
            abort(404);
        }

        return response()->json([
            'data' => PriceHistory::forIngredient($companyId, (int) $ingredient->id),
            'meta' => ['threshold_percent' => (float) CostSettings::threshold($companyId), 'unit' => $ingredient->unit?->value],
        ]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);
        $companyId = $this->tenant->requiredId();
        $days = max(1, min(365, (int) $request->query('days', 30)));
        $rows = PriceHistory::alerts($companyId, now()->subDays($days)->startOfDay());
        $alerts = PriceAlerts::present($companyId, $rows, withDishes: true);
        if ($request->query('seen') === 'unseen') {
            $alerts = array_values(array_filter($alerts, static fn (array $a): bool => ! $a['seen']));
        }

        return response()->json([
            'data' => $alerts,
            'meta' => ['days' => $days, 'threshold_percent' => (float) CostSettings::threshold($companyId)],
        ]);
    }

    public function markSeen(Request $request, int $line, MarkPriceAlertSeenAction $action): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        try {
            $marked = $action->handle($line, $request->user());
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['line_id' => $line, 'seen' => true, 'marked_now' => $marked]]);
    }

    public function foodCosts(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::ReportsView);
        $companyId = $this->tenant->requiredId();
        $food = FoodCost::forCompany($companyId);
        $rows = array_merge($food->rows(), $food->mealRows());
        if ($request->boolean('over')) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['over_target']));
        }

        return response()->json([
            'data' => $rows,
            'meta' => ['company_target_pct' => (float) CostSettings::target($companyId)],
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
