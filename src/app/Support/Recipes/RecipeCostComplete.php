<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Models\Product;
use App\Models\ProductRecipe;

/**
 * LAUNCH review add-on (A1) — whether a product's live recipe cost is
 * complete: every recipe ingredient has a cost (a raw ingredient once a priced
 * purchase set its weighted average; a prep item once every raw ingredient it
 * uses has one). While one has "No cost yet" the recipe editor shows the total
 * as incomplete. A product with no recipe is complete (there is nothing to cost).
 */
final class RecipeCostComplete
{
    public static function forProduct(Product $product): bool
    {
        $lines = $product->relationLoaded('recipeLines')
            ? $product->recipeLines
            : $product->recipeLines()->with('ingredient')->get();
        if ($lines->isEmpty()) {
            return true;
        }

        $graph = PrepGraph::forCompany((int) $product->company_id);

        return $lines->every(static fn (ProductRecipe $line): bool => $line->ingredient !== null
            && $graph->costComplete((int) $line->ingredient_id));
    }
}
