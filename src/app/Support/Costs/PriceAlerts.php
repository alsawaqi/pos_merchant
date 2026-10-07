<?php

declare(strict_types=1);

namespace App\Support\Costs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH costs & allergens add-on (tester calls 1 and 2) — a price-change
 * alert as the portal shows it ({@see PriceHistory::alerts()} finds them):
 *
 *   {line_id, ingredient {uuid, name, name_ar, unit}, old_unit_cost,
 *    new_unit_cost (per base unit, 6 dp), change_pct (+ up / − down),
 *    supplier {uuid, name} | null, received_at, receipt_uuid,
 *    receipt_reference, seen, seen_at, seen_by,
 *    dishes: [{product_uuid, name, name_ar, product_type, status,
 *              cost_baisas, food_cost_pct, previous_food_cost_pct,
 *              target_pct, over_target, crossed_target}] | null}
 *
 * The dishes are those whose cost moves with the ingredient (recipes, prep
 * items, combos); each is costed at today's costs with this ingredient at
 * the NEW purchase price (cost_baisas, food_cost_pct) and at the OLD one
 * (previous_food_cost_pct); crossed_target = over target at the new price
 * and not at the old one ("crossed because of the price change"). They are
 * left out (null) for a user who may not see costs.
 */
final class PriceAlerts
{
    /**
     * @param  Collection<int, object>  $rows  alerting lines ({@see PriceHistory::alerts()})
     * @return list<array<string, mixed>>
     */
    public static function present(int $companyId, Collection $rows, bool $withDishes): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $ingredients = DB::table('pos_ingredients')->where('company_id', $companyId)
            ->whereIn('id', $rows->pluck('ingredient_id')->unique()->all())->get(['id', 'uuid', 'name', 'name_ar', 'unit'])->keyBy('id');
        $reviews = DB::table('pos_price_alert_reviews as v')->leftJoin('pos_users as u', 'u.id', '=', 'v.seen_by_user_id')
            ->where('v.company_id', $companyId)->whereIn('v.purchase_receipt_line_id', $rows->pluck('id')->all())
            ->get(['v.purchase_receipt_line_id', 'v.seen_at', 'u.name as seen_by'])->keyBy('purchase_receipt_line_id');
        $food = $withDishes ? FoodCost::forCompany($companyId) : null;

        return $rows->map(static function (object $row) use ($ingredients, $reviews, $food): array {
            $ingredient = $ingredients->get($row->ingredient_id);
            $review = $reviews->get($row->id);

            return [
                'line_id' => (int) $row->id,
                'ingredient' => [
                    'uuid' => $ingredient?->uuid,
                    'name' => $ingredient?->name,
                    'name_ar' => $ingredient?->name_ar,
                    'unit' => $ingredient?->unit ?? $row->unit,
                ],
                'old_unit_cost' => $row->previous_unit_cost,
                'new_unit_cost' => $row->unit_cost,
                'change_pct' => $row->change_pct,
                'supplier' => $row->supplier_uuid !== null ? ['uuid' => (string) $row->supplier_uuid, 'name' => (string) $row->supplier_name] : null,
                'received_at' => Carbon::parse($row->received_at)->toIso8601String(),
                'receipt_uuid' => (string) $row->receipt_uuid,
                'receipt_reference' => $row->receipt_reference,
                'seen' => $review !== null,
                'seen_at' => $review !== null ? Carbon::parse($review->seen_at)->toIso8601String() : null,
                'seen_by' => $review?->seen_by,
                'dishes' => $food === null ? null : self::dishes($food, (int) $row->ingredient_id, (string) $row->previous_unit_cost, (string) $row->unit_cost),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public static function dishes(FoodCost $food, int $ingredientId, string $oldCost, string $newCost): array
    {
        $before = $food->withIngredientCost($ingredientId, $oldCost);
        $after = $food->withIngredientCost($ingredientId, $newCost);
        $out = [];
        foreach ($food->dishesUsing($ingredientId) as $productId) {
            $now = $after->product($productId);
            $was = $before->product($productId);
            if ($now === null || $was === null) {
                continue;
            }
            $out[] = [
                'product_uuid' => $now['product_uuid'],
                'name' => $now['name'],
                'name_ar' => $now['name_ar'],
                'product_type' => $now['product_type'],
                'status' => $now['status'],
                'cost_baisas' => $now['cost_baisas'],
                'food_cost_pct' => $now['food_cost_pct'],
                'previous_food_cost_pct' => $was['food_cost_pct'],
                'target_pct' => $now['target_pct'],
                'over_target' => $now['over_target'],
                'crossed_target' => $now['over_target'] && ! $was['over_target'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => [! $a['crossed_target'], ! $a['over_target'], $a['name']] <=> [! $b['crossed_target'], ! $b['over_target'], $b['name']]);

        return $out;
    }
}
