<?php

declare(strict_types=1);

/*
 * LAUNCH-P3 test fixtures shared by the tests in this folder (required, not
 * a test file). Every helper is prefixed p3 so it never collides with the
 * suite's other global helpers.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use Illuminate\Support\Facades\DB;

if (! function_exists('p3Ingredient')) {
    /** A raw ingredient with a base unit and a cost per base unit. */
    function p3Ingredient(Company $company, string $name, string $unit, string $cost, array $extra = []): Ingredient
    {
        return Ingredient::factory()->for($company, 'company')->create($extra + [
            'name' => $name,
            'unit' => $unit,
            'default_unit_cost' => $cost,
            'min_stock_threshold' => null,
        ]);
    }

    /**
     * A prep item written straight to the tables (no API, no permission):
     * $lines = [[Ingredient, base quantity per batch], ...].
     *
     * @param  list<array{0: Ingredient, 1: string}>  $lines
     */
    function p3Prep(Company $company, string $name, string $unit, string $yield, array $lines): Ingredient
    {
        $prep = Ingredient::factory()->for($company, 'company')->create([
            'name' => $name,
            'unit' => $unit,
            'default_unit_cost' => '0',
            'min_stock_threshold' => null,
            'is_prep' => true,
            'prep_yield_quantity' => $yield,
        ]);
        foreach ($lines as $i => [$ingredient, $quantity]) {
            IngredientRecipe::query()->create([
                'prep_ingredient_id' => $prep->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => $quantity,
                'sort_order' => $i,
            ]);
        }

        return $prep;
    }

    /** Put a balance on a branch the way the ledger would (one movement + the balance row). */
    function p3Stock(Branch $branch, Ingredient $ingredient, string $quantity): void
    {
        DB::table('pos_stock_movements')->insert([
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'movement_type' => 'adjustment',
            'quantity' => $quantity,
            'unit_cost_at_time' => (string) $ingredient->default_unit_cost,
            'occurred_at' => now()->subDay(),
            'created_at' => now(),
        ]);
        DB::table('pos_branch_stock')->updateOrInsert(
            ['branch_id' => $branch->id, 'ingredient_id' => $ingredient->id],
            ['quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()],
        );
    }
}
