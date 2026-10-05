<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\ItemBarcode;
use Illuminate\Support\Collection;

/**
 * LAUNCH review add-on (A2, A3, A5) — one container as the portal reads it:
 * its token (what every amount picker sends), the server-made display name in
 * English and Arabic ("bottle 1.5 l", "crate (12 × bottle 1 l)"), what it
 * holds, its leaf and how many of the leaf one holds, whether tills count in
 * it, whether its size is locked (only when worked out), and its barcodes.
 */
final class ContainerPresenter
{
    /**
     * @param  Collection<int, IngredientAltUnit>  $all  the item's containers
     * @param  array<int, true>|null  $usedIds  size-locked container ids (null = not worked out)
     * @param  Collection<int, ItemBarcode>|null  $barcodes  the item's live barcodes
     * @return array<string, mixed>
     */
    public static function one(IngredientAltUnit $container, Ingredient $ingredient, Collection $all, ?array $usedIds = null, ?Collection $barcodes = null): array
    {
        $content = $container->contains_unit_id === null
            ? null
            : $all->first(static fn (IngredientAltUnit $c): bool => (int) $c->id === (int) $container->contains_unit_id);
        [$leaf, $perLeaf] = Containers::leaf($container, $all);

        return [
            'id' => $container->id,
            'uuid' => $container->uuid,
            'token' => $container->token(),
            'ingredient_id' => $container->ingredient_id,
            'name' => $container->name,
            'name_ar' => $container->name_ar,
            // decimal:4 cast → string, so the factor keeps full precision.
            'factor' => (string) $container->factor,
            'sort_order' => $container->sort_order,
            'contains_unit_uuid' => $content?->uuid,
            'contains_quantity' => $container->contains_quantity !== null ? Containers::trim((string) $container->contains_quantity) : null,
            'display_name' => Containers::displayName($container, $ingredient, $all, 'en'),
            'display_name_ar' => Containers::displayName($container, $ingredient, $all, 'ar'),
            'is_leaf' => $container->contains_unit_id === null,
            'leaf_uuid' => $leaf->uuid,
            'leaf_pieces' => Containers::trim((string) $perLeaf),
            'is_count_container' => (int) ($ingredient->count_container_id ?? 0) === (int) $container->id,
            'size_locked' => $usedIds === null ? null : isset($usedIds[(int) $container->id]),
            'barcodes' => $barcodes === null ? [] : $barcodes
                ->filter(static fn (ItemBarcode $b): bool => (int) ($b->container_id ?? 0) === (int) $container->id)
                ->map(static fn (ItemBarcode $b): array => $b->summary())
                ->values()
                ->all(),
            'created_at' => $container->created_at?->toIso8601String(),
            'updated_at' => $container->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Every live container of the item, in order.
     *
     * @param  array<int, true>|null  $usedIds
     * @return list<array<string, mixed>>
     */
    public static function all(Ingredient $ingredient, ?array $usedIds = null): array
    {
        $all = Containers::of($ingredient);
        $barcodes = $ingredient->relationLoaded('barcodes')
            ? $ingredient->barcodes
            : ItemBarcode::query()->where('ingredient_id', $ingredient->id)->get();

        return $all
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(static fn (IngredientAltUnit $c): array => self::one($c, $ingredient, $all, $usedIds, $barcodes))
            ->values()
            ->all();
    }
}
