<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\Meal;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH combo add-on (owner decision 4, tester call 2.2; fix order 1 C-4)
 * — a meal's mains: the standard, non-internal products of its categories
 * (new products join automatically) minus the ones the merchant unticked.
 *
 * A main belongs to at most ONE active, on-sale meal. Two meals clash on a
 * product when both are active, neither has ended (an `on_sale_until`
 * before today does not count) and their sale dates overlap. A meal save, a
 * product create and a product's category move are refused on a clash,
 * naming the product and both meals.
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

    /** Today in the merchant's calendar (Asia/Muscat). */
    public static function today(): string
    {
        return Carbon::now(config('pos.business_timezone', 'Asia/Muscat'))->format('Y-m-d');
    }

    /** Has a meal with these dates ended (its last day is before today)? */
    public static function ended(mixed $until): bool
    {
        $day = self::day($until);

        return $day !== null && $day < self::today();
    }

    /** Do two date ranges (null = open) overlap? */
    public static function overlap(mixed $fromA, mixed $untilA, mixed $fromB, mixed $untilB): bool
    {
        [$fromA, $untilA, $fromB, $untilB] = array_map(self::day(...), [$fromA, $untilA, $fromB, $untilB]);

        return ($fromA === null || $untilB === null || $fromA <= $untilB)
            && ($fromB === null || $untilA === null || $fromB <= $untilA);
    }

    /**
     * The other active, not-ended meals whose dates overlap these dates.
     *
     * @return Collection<int, object> each with categories and excluded (ids)
     */
    public static function rivals(int $companyId, ?int $mealId, mixed $from, mixed $until): Collection
    {
        $meals = Meal::query()->where('company_id', $companyId)->where('status', Meal::STATUS_ACTIVE)
            ->when($mealId !== null, static fn ($q) => $q->where('id', '<>', $mealId))->orderBy('name')->get()
            ->filter(static fn (Meal $m): bool => ! self::ended($m->on_sale_until) && self::overlap($from, $until, $m->on_sale_from, $m->on_sale_until))
            ->values();
        $ids = $meals->pluck('id')->all();
        $categories = DB::table('pos_meal_categories')->whereIn('meal_id', $ids ?: [0])->get()->groupBy('meal_id');
        $excluded = DB::table('pos_meal_excluded_products')->whereIn('meal_id', $ids ?: [0])->get()->groupBy('meal_id');

        return $meals->map(static fn (Meal $m): object => (object) [
            'id' => (int) $m->id,
            'uuid' => (string) $m->uuid,
            'name' => (string) $m->name,
            'from' => self::day($m->on_sale_from),
            'until' => self::day($m->on_sale_until),
            'categories' => collect($categories->get($m->id) ?? [])->pluck('category_id')->map(static fn ($id): int => (int) $id)->all(),
            'excluded' => collect($excluded->get($m->id) ?? [])->pluck('product_id')->map(static fn ($id): int => (int) $id)->all(),
        ]);
    }

    /**
     * The mains this setup would share with another active, not-ended meal
     * whose dates overlap (none when this meal itself has ended).
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $excludedIds
     * @return list<array{product: string, product_id: int, meal: string, meal_uuid: string}>
     */
    public static function clashes(int $companyId, ?int $mealId, array $categoryIds, array $excludedIds, mixed $from = null, mixed $until = null): array
    {
        if (self::ended($until)) {
            return [];
        }
        $mine = self::of($companyId, $categoryIds, $excludedIds);
        if ($mine === []) {
            return [];
        }
        $out = [];
        foreach (self::rivals($companyId, $mealId, $from, $until) as $other) {
            $theirs = self::of($companyId, $other->categories, $other->excluded);
            foreach (array_intersect_key($mine, $theirs) as $productId => $name) {
                $out[] = ['product' => $name, 'product_id' => (int) $productId, 'meal' => $other->name, 'meal_uuid' => $other->uuid];
            }
        }

        return $out;
    }

    /**
     * Fix order 1 (C-4) — a standard product put in $categoryId (created,
     * or moved there) would be a main of these active, not-ended meals; a
     * clash when two of them overlap in dates. Returns the message, or null.
     */
    public static function productClash(int $companyId, ?int $productId, string $productName, ?int $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }
        $pair = self::clashPair(self::rivals($companyId, null, null, null), $productId, $categoryId);

        return $pair === null ? null : self::productClashMessage($productName, $pair[0], $pair[1]);
    }

    /**
     * Fix order 2 (C-15) — the first two of these meals (active, not ended:
     * {@see rivals()} with no dates) that would both take a standard product
     * in $categoryId as a main while their dates overlap, or null. The menu
     * import loads the meals once and asks for every row.
     *
     * @param  Collection<int, object>  $meals
     * @return array{0: string, 1: string}|null the two meal names
     */
    public static function clashPair(Collection $meals, ?int $productId, int $categoryId): ?array
    {
        $covering = $meals->filter(static fn (object $meal): bool => in_array($categoryId, $meal->categories, true)
            && ($productId === null || ! in_array($productId, $meal->excluded, true)))->values();
        foreach ($covering as $i => $a) {
            foreach ($covering->slice($i + 1) as $b) {
                if (self::overlap($a->from, $a->until, $b->from, $b->until)) {
                    return [$a->name, $b->name];
                }
            }
        }

        return null;
    }

    /** The product-clash message (English / Arabic). */
    public static function productClashMessage(string $productName, string $meal, string $otherMeal): string
    {
        return sprintf('"%1$s" would be in both "%2$s" and "%3$s": untick it in one of the meals first. / "%1$s" سيكون في وجبتين "%2$s" و"%3$s": أزل تحديده من إحداهما أولاً.',
            $productName, $meal, $otherMeal);
    }

    /** The clash message (English / Arabic) for one shared main. */
    public static function message(array $clash): string
    {
        return sprintf('"%s" is already a main of the meal "%s". Untick it here or there. / "%s" موجود في وجبة "%s". أزل التحديد هنا أو هناك.',
            $clash['product'], $clash['meal'], $clash['product'], $clash['meal']);
    }

    private static function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
