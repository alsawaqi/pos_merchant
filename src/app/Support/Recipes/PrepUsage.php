<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use App\Models\ProductRecipe;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P3 P3-4 — where an ingredient or prep item is used: product recipes,
 * add-on option stock-usage lines and (live) prep recipes. Guards deletes and
 * unit changes, and finds what a prep recipe change must re-publish.
 */
final readonly class PrepUsage
{
    public function __construct(
        public int $productRecipes,
        public int $addonLines,
        public int $prepRecipes,
    ) {}

    public static function of(Ingredient $ingredient): self
    {
        return new self(
            ProductRecipe::query()->where('ingredient_id', $ingredient->id)->count(),
            AddOnConsumption::query()->where('ingredient_id', $ingredient->id)->count(),
            self::livePrepRecipeLines($ingredient->id),
        );
    }

    /** Lines of NON-deleted prep items that list this ingredient. */
    public static function livePrepRecipeLines(int $ingredientId): int
    {
        return IngredientRecipe::query()
            ->where('ingredient_id', $ingredientId)
            ->whereHas('prepItem', static fn ($q) => $q->whereNull('deleted_at'))
            ->count();
    }

    public function total(): int
    {
        return $this->productRecipes + $this->addonLines + $this->prepRecipes;
    }

    /** "2 product recipe(s), 1 prep recipe(s)". */
    public function describe(): string
    {
        $parts = [];
        if ($this->productRecipes > 0) {
            $parts[] = $this->productRecipes.' product recipe(s)';
        }
        if ($this->addonLines > 0) {
            $parts[] = $this->addonLines.' add-on option line(s)';
        }
        if ($this->prepRecipes > 0) {
            $parts[] = $this->prepRecipes.' prep recipe(s)';
        }

        return implode(', ', $parts);
    }

    /**
     * Bump every product and add-on option (and its group) that uses this
     * prep item directly or through other prep items: the device config
     * re-emits a product / add-on group only when its updated_at moves.
     */
    public static function touchDependents(Ingredient $prep): void
    {
        $affected = [(int) $prep->id];
        $graph = PrepGraph::load((int) $prep->company_id);
        for ($i = 0; $i < count($affected); $i++) {
            foreach ($graph->prepItemsUsing($affected[$i]) as $parent) {
                if (! in_array($parent, $affected, true)) {
                    $affected[] = $parent;
                }
            }
        }

        $now = now();
        $productIds = ProductRecipe::query()->whereIn('ingredient_id', $affected)->pluck('product_id')->unique()->all();
        if ($productIds !== []) {
            DB::table('pos_products')->whereIn('id', $productIds)->update(['updated_at' => $now]);
        }

        $addonIds = AddOnConsumption::query()->whereIn('ingredient_id', $affected)->pluck('add_on_id')->unique()->all();
        if ($addonIds !== []) {
            DB::table('pos_addons')->whereIn('id', $addonIds)->update(['updated_at' => $now]);
            $groupIds = AddOn::withTrashed()->whereIn('id', $addonIds)->pluck('add_on_group_id')->unique()->all();
            if ($groupIds !== []) {
                DB::table('pos_addon_groups')->whereIn('id', $groupIds)->update(['updated_at' => $now]);
            }
        }
    }
}
