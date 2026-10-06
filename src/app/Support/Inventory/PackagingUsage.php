<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Exceptions\LocalizedException;
use App\Models\OrderPackagingLine;
use App\Support\Catalogue\OrderTypes;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * LAUNCH packaging add-on (fix order PK-B1, M2/M3) — which live per-order
 * packaging lists name an ingredient or a product, and the refusal of a
 * change that would make the item stop being taken (deleted, inactive,
 * branch-use, no longer counted in pieces) or be taken wrongly. pos_api takes
 * the list by the stored base quantity: the item must stay valid while it is
 * on a list. The merchant removes it from Inventory → Order packaging first.
 */
final class PackagingUsage
{
    /** @return list<string> the order types whose live list names the ingredient */
    public static function ingredientLists(int $ingredientId): array
    {
        return self::types(OrderPackagingLine::query()->where('ingredient_id', $ingredientId));
    }

    /** @return list<string> the order types whose live list names the product */
    public static function productLists(int $productId): array
    {
        return self::types(OrderPackagingLine::query()->where('product_id', $productId));
    }

    /**
     * @param  list<string>  $lists
     *
     * @throws LocalizedException code "on_packaging_list" when $lists is not empty
     */
    public static function refuse(array $lists, string $name, string $what, string $whatAr): void
    {
        if ($lists === []) {
            return;
        }
        $mask = 0;
        foreach ($lists as $type) {
            $mask |= OrderTypes::BUCKETS[$type] ?? 0;
        }

        throw new LocalizedException(
            'on_packaging_list',
            sprintf('"%s" is on the Order packaging list (%s), so it cannot %s. Remove it from the Order packaging list first.', $name, OrderTypes::label($mask), $what),
            sprintf('"%s" موجود في قائمة تغليف الطلب (%s)، لذا لا يمكن %s. أزله من قائمة تغليف الطلب أولاً.', $name, OrderTypes::labelAr($mask), $whatAr),
            ['order_types' => $lists],
        );
    }

    /**
     * Fix order PK-B1 (M1) — a physical-item line's typed pack form ("1 ×
     * pack of 50") while it still adds up to the stored pieces; else
     * [null, null] (the line reads in pieces, what pos_api takes).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function packForm(?int $productId, string $quantity, ?string $enteredUnit, string|int|float|null $enteredQuantity): array
    {
        $uuid = ContainerToken::decode($enteredUnit);
        if ($productId === null || $uuid === null || $enteredQuantity === null || $enteredQuantity === '') {
            return [null, null];
        }
        $pack = \App\Models\ProductPack::query()->where('product_id', $productId)->where('uuid', $uuid)->first();
        if ($pack === null) {
            return [null, null];
        }
        $pieces = BigDecimal::of((string) $enteredQuantity)->multipliedBy((string) $pack->pieces)->toScale(3, RoundingMode::HALF_UP);
        if (! $pieces->isEqualTo(BigDecimal::of($quantity)->toScale(3, RoundingMode::HALF_UP))) {
            return [null, null];
        }

        return [(string) $enteredUnit, \App\Support\Recipes\RecipeQuantity::trim(BigDecimal::of((string) $enteredQuantity))];
    }

    /** @return list<string> */
    private static function types($query): array
    {
        $types = $query->distinct()->pluck('order_type')->map(static fn ($t): string => (string) $t)->all();

        return array_values(array_filter(array_keys(OrderTypes::BUCKETS), static fn (string $b): bool => in_array($b, $types, true)));
    }
}
