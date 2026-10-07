<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\Meal;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH combo add-on (owner decision 4, tester call 2.2) — a meal's mains:
 * the standard, non-internal products of its categories (new products join
 * automatically) minus the ones the merchant unticked. A main belongs to at
 * most ONE active meal: a save that would make two active meals cover the
 * same product is refused, naming the product and the other meal.
 */
final class MealMains
{
    /**
     * @param  list<int>  $categoryIds
     * @param  list<int>  $excludedIds
     * @return array<int, string> product id => name
     */
    public static function of(int $companyId, array $categoryIds, array $excludedIds): array
    {
        return Product::query()->where('company_id', $companyId)
            ->where('product_type', Product::TYPE_STANDARD)->where('is_internal', false)
            ->whereIn('category_id', $categoryIds === [] ? [0] : $categoryIds)
            ->whereNotIn('id', $excludedIds === [] ? [0] : $excludedIds)
            ->orderBy('name')->pluck('name', 'id')->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])->all();
    }

    /**
     * The mains this setup would share with another ACTIVE meal.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $excludedIds
     * @return list<array{product: string, product_id: int, meal: string, meal_uuid: string}>
     */
    public static function clashes(int $companyId, ?int $mealId, array $categoryIds, array $excludedIds): array
    {
        $mine = self::of($companyId, $categoryIds, $excludedIds);
        if ($mine === []) {
            return [];
        }
        $out = [];
        $others = Meal::query()->where('company_id', $companyId)->where('status', Meal::STATUS_ACTIVE)
            ->when($mealId !== null, static fn ($q) => $q->where('id', '<>', $mealId))->orderBy('name')->get();
        foreach ($others as $other) {
            $theirs = self::of(
                $companyId,
                DB::table('pos_meal_categories')->where('meal_id', $other->id)->pluck('category_id')->map(static fn ($id): int => (int) $id)->all(),
                DB::table('pos_meal_excluded_products')->where('meal_id', $other->id)->pluck('product_id')->map(static fn ($id): int => (int) $id)->all(),
            );
            foreach (array_intersect_key($mine, $theirs) as $productId => $name) {
                $out[] = ['product' => $name, 'product_id' => (int) $productId, 'meal' => (string) $other->name, 'meal_uuid' => (string) $other->uuid];
            }
        }

        return $out;
    }

    /** The clash message (English / Arabic) for one shared main. */
    public static function message(array $clash): string
    {
        return sprintf('"%s" is already a main of the meal "%s". Untick it here or there. / "%s" موجود في وجبة "%s". أزل التحديد هنا أو هناك.',
            $clash['product'], $clash['meal'], $clash['product'], $clash['meal']);
    }
}
