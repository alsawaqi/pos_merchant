<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Models\Product;
use App\Models\ProductRecipeVersion;
use Illuminate\Support\Collection;

/**
 * LAUNCH-P3 P3-2 — a product's recipe history, from the append-only
 * pos_product_recipe_versions rows (each holds the recipe BEFORE an edit,
 * dated at the edit, with who and the note) plus the current lines.
 *
 * Change k (oldest = 1) turns snapshot k into snapshot k+1 — or into the
 * current recipe for the newest change — and its result is "version k".
 * Every line added, removed or changed is listed before → after in the unit
 * it was entered in (pre-P3 rows have no entered unit: their base unit).
 */
final readonly class ProductRecipeHistory
{
    public function __construct(
        private RecipeQuantity $quantities,
    ) {}

    /**
     * @return array{current: array{version: int, lines: list<array<string, mixed>>}, versions: list<array<string, mixed>>}
     */
    public function build(Product $product): array
    {
        /** @var Collection<int, ProductRecipeVersion> $rows */
        $rows = ProductRecipeVersion::query()
            ->where('product_id', $product->id)
            ->with('editedBy:id,name')
            ->orderBy('edited_at')
            ->orderBy('id')
            ->get();

        $current = RecipeLineChanges::fromProductLines(
            $product->recipeLines()->with('ingredient')->get(),
            $this->quantities,
        );

        $versions = [];
        $count = $rows->count();
        foreach ($rows->values() as $index => $row) {
            $before = RecipeLineChanges::fromSnapshot(is_array($row->recipe_json) ? $row->recipe_json : null);
            $next = $rows->get($index + 1);
            $after = $next !== null
                ? RecipeLineChanges::fromSnapshot(is_array($next->recipe_json) ? $next->recipe_json : null)
                : $current;

            $versions[] = [
                'version' => $index + 1,
                'edited_at' => $row->edited_at?->toIso8601String(),
                'edited_by' => $row->editedBy === null ? null : [
                    'id' => (int) $row->editedBy->id,
                    'name' => (string) $row->editedBy->name,
                ],
                'note' => $row->note,
                'changes' => RecipeLineChanges::diff($before, $after),
            ];
        }

        return [
            'current' => [
                'version' => $count,
                'lines' => self::lines($current),
            ],
            // Newest first, like every history in the portal.
            'versions' => array_reverse($versions),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function lines(array $lines): array
    {
        return array_values(array_map(static fn (array $l): array => [
            'ingredient_id' => $l['ingredient_id'],
            'ingredient' => $l['ingredient_name'],
            'is_prep' => $l['is_prep'],
            'amount' => RecipeLineChanges::amount($l),
            'base' => RecipeLineChanges::baseAmount($l),
        ], $lines));
    }
}
