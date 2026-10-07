<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use Illuminate\Support\Facades\DB;

/**
 * LAUNCH costs & allergens add-on — keeps the worked-out allergens fresh.
 *
 * A dish's allergens are never stored: the portal ({@see self::graph()}) and
 * pos_api ({@see Allergens::load()}) work them out from the merchant's rows
 * on every read. Devices, though, pull products and add-on groups by
 * updated_at, so whatever changes an allergen somewhere down the tree must
 * bump every product and add-on option above it:
 *
 *   an ingredient (its ticks, or a prep recipe)  → the prep items using it,
 *     every level up → the products whose recipe uses any of them
 *   a product (its ticks, its recipe or components) → the products using it
 *     as a component, every level up
 *   and the add-on options (and their groups) whose ingredient, linked
 *   product or 'add' stock-usage lines name any of them.
 *
 * Combos and meals are re-sent on every device pull, and the QR / tablet
 * menu is built live, so they need no bump. Every read is one merchant's.
 */
final class AllergenSync
{
    /** The merchant's allergen graph, memoised for the current request (writers {@see forget()} it). */
    public static function graph(int $companyId): Allergens
    {
        $attributes = request()->attributes;
        $key = 'launch_costs.allergens.'.$companyId;
        $graph = $attributes->get($key);
        if (! $graph instanceof Allergens) {
            $graph = Allergens::load($companyId);
            $attributes->set($key, $graph);
        }

        return $graph;
    }

    public static function forget(int $companyId): void
    {
        request()->attributes->remove('launch_costs.allergens.'.$companyId);
    }

    /**
     * Bump everything whose allergens may have changed.
     *
     * @param  list<int>  $ingredientIds
     * @param  list<int>  $productIds
     */
    public static function touch(int $companyId, array $ingredientIds = [], array $productIds = []): void
    {
        self::forget($companyId);

        $ingredients = self::upPrepItems($companyId, array_values(array_unique(array_map('intval', $ingredientIds))));
        $products = array_map('intval', $productIds);
        if ($ingredients !== []) {
            $products = array_merge($products, DB::table('pos_product_recipes')
                ->join('pos_products', 'pos_products.id', '=', 'pos_product_recipes.product_id')
                ->where('pos_products.company_id', $companyId)
                ->whereIn('pos_product_recipes.ingredient_id', $ingredients)
                ->pluck('pos_product_recipes.product_id')->map(static fn ($id): int => (int) $id)->all());
        }
        $products = self::upComponents($companyId, array_values(array_unique($products)));

        $now = now();
        if ($products !== []) {
            DB::table('pos_products')->where('company_id', $companyId)->whereIn('id', $products)->update(['updated_at' => $now]);
        }

        $addonIds = DB::table('pos_addons')->where('company_id', $companyId)
            ->where(static function ($q) use ($ingredients, $products): void {
                $q->whereIn('ingredient_id', $ingredients ?: [0])->orWhereIn('linked_product_id', $products ?: [0]);
            })->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $addonIds = array_merge($addonIds, DB::table('pos_addon_consumptions')
            ->join('pos_addons', 'pos_addons.id', '=', 'pos_addon_consumptions.add_on_id')
            ->where('pos_addons.company_id', $companyId)
            ->where(static function ($q) use ($ingredients, $products): void {
                $q->whereIn('pos_addon_consumptions.ingredient_id', $ingredients ?: [0])
                    ->orWhereIn('pos_addon_consumptions.component_product_id', $products ?: [0]);
            })->pluck('pos_addon_consumptions.add_on_id')->map(static fn ($id): int => (int) $id)->all());
        $addonIds = array_values(array_unique($addonIds));
        if ($addonIds !== []) {
            DB::table('pos_addons')->whereIn('id', $addonIds)->update(['updated_at' => $now]);
            $groupIds = DB::table('pos_addons')->whereIn('id', $addonIds)->pluck('add_on_group_id')->unique()->values()->all();
            DB::table('pos_addon_groups')->where('company_id', $companyId)->whereIn('id', $groupIds ?: [0])->update(['updated_at' => $now]);
        }
    }

    /**
     * The ingredients and every prep item that uses one of them, any level up.
     *
     * @param  list<int>  $ingredientIds
     * @return list<int>
     */
    private static function upPrepItems(int $companyId, array $ingredientIds): array
    {
        $all = $ingredientIds;
        $frontier = $ingredientIds;
        while ($frontier !== []) {
            $parents = DB::table('pos_ingredient_recipes')
                ->join('pos_ingredients', 'pos_ingredients.id', '=', 'pos_ingredient_recipes.prep_ingredient_id')
                ->where('pos_ingredients.company_id', $companyId)
                ->whereIn('pos_ingredient_recipes.ingredient_id', $frontier)
                ->pluck('pos_ingredient_recipes.prep_ingredient_id')->map(static fn ($id): int => (int) $id)->unique()->all();
            $frontier = array_values(array_diff($parents, $all));
            $all = array_merge($all, $frontier);
        }

        return $all;
    }

    /**
     * The products and every product that has one of them as a component,
     * any level up.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private static function upComponents(int $companyId, array $productIds): array
    {
        $all = $productIds;
        $frontier = $productIds;
        while ($frontier !== []) {
            $parents = DB::table('pos_product_components')
                ->join('pos_products', 'pos_products.id', '=', 'pos_product_components.product_id')
                ->where('pos_products.company_id', $companyId)
                ->whereIn('pos_product_components.component_product_id', $frontier)
                ->pluck('pos_product_components.product_id')->map(static fn ($id): int => (int) $id)->unique()->all();
            $frontier = array_values(array_diff($parents, $all));
            $all = array_merge($all, $frontier);
        }

        return $all;
    }
}
