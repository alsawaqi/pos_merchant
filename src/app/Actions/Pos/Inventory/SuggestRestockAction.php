<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Pos\Reports\InventoryConsumptionReportAction;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5c — stock-allocation smart suggestions (blueprint §5.6.4).
 *
 * For one branch, proposes a restock quantity per ingredient from data we
 * already track — current branch stock, the ingredient's min_stock_threshold,
 * and the trailing consumption rate from the movement ledger. Non-binding:
 * the manager pre-fills a restock request from this, then edits + submits via
 * the normal CreateRestockRequest flow.
 *
 * Per ingredient (over the requested branch only):
 *   daily        = consumed in the last {windowDays} / windowDays
 *   target_level = max(min_stock_threshold, daily * coverDays)
 *   suggested    = max(0, target_level - current_quantity)
 * Only ingredients with suggested > 0 are returned, biggest gap first.
 *
 * "Consumed" matches the §5.11.3 Inventory Consumption Report — the negative
 * sale / add-on / adjustment movements. Waste, loss and transfers are separate
 * flows and deliberately don't drive the burn rate.
 */
final readonly class SuggestRestockAction
{
    /**
     * Negative movement types that count as consumption — mirrors
     * {@see InventoryConsumptionReportAction}.
     *
     * @var list<string>
     */
    private const CONSUMPTION_TYPES = [
        StockMovementType::SaleConsumption->value,
        StockMovementType::AddOnConsumption->value,
        StockMovementType::Adjustment->value,
    ];

    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(Branch $branch, int $windowDays, int $coverDays): array
    {
        $companyId = $this->tenant->requiredId();
        $since = Carbon::now()->subDays($windowDays);

        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->get();

        if ($ingredients->isEmpty()) {
            return [];
        }

        $ingredientIds = $ingredients->pluck('id')->all();

        // Current per-ingredient balance at THIS branch.
        $balances = DB::table('pos_branch_stock')
            ->where('branch_id', $branch->id)
            ->whereIn('ingredient_id', $ingredientIds)
            ->pluck('quantity', 'ingredient_id');

        // Trailing consumption at THIS branch over the window — the absolute
        // value of the negative consumption deltas.
        $consumption = DB::table('pos_stock_movements')
            ->where('branch_id', $branch->id)
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereIn('movement_type', self::CONSUMPTION_TYPES)
            ->where('quantity', '<', 0)
            ->where('occurred_at', '>=', $since)
            ->groupBy('ingredient_id')
            ->selectRaw('ingredient_id, ABS(SUM(quantity)) AS consumed')
            ->pluck('consumed', 'ingredient_id');

        $suggestions = [];
        foreach ($ingredients as $ingredient) {
            $current = (float) ($balances[$ingredient->id] ?? 0);
            $consumed = (float) ($consumption[$ingredient->id] ?? 0);
            $hasThreshold = $ingredient->min_stock_threshold !== null;
            $threshold = $hasThreshold ? (float) $ingredient->min_stock_threshold : 0.0;

            $daily = $consumed / $windowDays;
            $forecast = $daily * $coverDays;
            $target = max($threshold, $forecast);
            $suggested = $target - $current;

            if ($suggested <= 0) {
                continue; // already at or above target — nothing to order
            }

            $suggestions[] = [
                'ingredient_id' => (int) $ingredient->id,
                'ingredient_uuid' => $ingredient->uuid,
                'name' => $ingredient->name,
                'unit' => $ingredient->unit->value,
                'current_quantity' => number_format($current, 3, '.', ''),
                'min_stock_threshold' => $hasThreshold ? number_format($threshold, 3, '.', '') : null,
                'consumed_in_window' => number_format($consumed, 3, '.', ''),
                'avg_daily_consumption' => number_format($daily, 3, '.', ''),
                'target_level' => number_format($target, 3, '.', ''),
                'suggested_quantity' => number_format($suggested, 3, '.', ''),
                'reason' => $this->reason($hasThreshold && $current < $threshold, $current < $forecast),
            ];
        }

        // Biggest gap first.
        usort($suggestions, static fn (array $a, array $b): int => (float) $b['suggested_quantity'] <=> (float) $a['suggested_quantity']);

        return $suggestions;
    }

    private function reason(bool $belowThreshold, bool $belowForecast): string
    {
        if ($belowThreshold && $belowForecast) {
            return 'below_threshold_and_forecast';
        }
        if ($belowThreshold) {
            return 'below_threshold';
        }

        return 'consumption_forecast';
    }
}
