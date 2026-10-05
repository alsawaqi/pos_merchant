<?php

declare(strict_types=1);

/*
 * LAUNCH review add-on (part B) test fixtures shared by the tests in this
 * folder (required, not a test file). Every helper is prefixed rv so it never
 * collides with the suite's other global helpers. Table-level writes (not the
 * models), so a fixture means the same whatever application code runs — the
 * fail-before run uses the base ef791f0.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('rvIngredient')) {
    /** A raw ingredient stored in $unit with a cost per base unit. */
    function rvIngredient(Company $company, string $name, string $unit = 'ml', string $cost = '0', array $extra = []): Ingredient
    {
        return Ingredient::factory()->for($company, 'company')->create($extra + [
            'name' => $name,
            'unit' => $unit,
            'default_unit_cost' => $cost,
            'min_stock_threshold' => null,
            'piece_unit_label' => null,
            'piece_unit_label_ar' => null,
            'units_per_piece' => null,
        ]);
    }

    /** A container row straight in the table (no API); returns its id. */
    function rvContainer(Ingredient $ingredient, string $name, string $factor, ?int $containsId = null, ?string $containsQuantity = null, ?string $nameAr = null): int
    {
        return (int) DB::table('pos_ingredient_units')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $ingredient->company_id,
            'ingredient_id' => $ingredient->id,
            'name' => $name,
            'name_ar' => $nameAr,
            'factor' => $factor,
            'sort_order' => 0,
            'contains_unit_id' => $containsId,
            'contains_quantity' => $containsQuantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    function rvContainerUuid(int $id): string
    {
        return (string) DB::table('pos_ingredient_units')->where('id', $id)->value('uuid');
    }

    /** The compact token naming a container ("#" + base64url of its uuid). */
    function rvToken(int $id): string
    {
        $hex = str_replace('-', '', rvContainerUuid($id));

        return '#'.rtrim(strtr(base64_encode((string) hex2bin($hex)), '+/', '-_'), '=');
    }

    /** Put a balance on a branch (NULL = the warehouse) the way the ledger would. */
    function rvStock(?Branch $branch, Ingredient $ingredient, string $quantity): void
    {
        DB::table('pos_stock_movements')->insert([
            'branch_id' => $branch?->id,
            'ingredient_id' => $ingredient->id,
            'movement_type' => 'adjustment',
            'quantity' => $quantity,
            'unit_cost_at_time' => (string) $ingredient->default_unit_cost,
            'occurred_at' => now()->subDay(),
            'created_at' => now(),
        ]);
        if ($branch === null) {
            DB::table('pos_ingredient_stock')->updateOrInsert(
                ['company_id' => $ingredient->company_id, 'ingredient_id' => $ingredient->id],
                ['quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()],
            );

            return;
        }
        DB::table('pos_branch_stock')->updateOrInsert(
            ['branch_id' => $branch->id, 'ingredient_id' => $ingredient->id],
            ['quantity' => $quantity, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** A breakdown balance row straight in the table. */
    function rvBreakdown(Company $company, ?Branch $branch, Ingredient $ingredient, int $containerId, string $pieces): void
    {
        DB::table('pos_stock_container_balances')->insert([
            'company_id' => $company->id,
            'branch_id' => $branch?->id,
            'ingredient_id' => $ingredient->id,
            'container_id' => $containerId,
            'pieces' => $pieces,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The breakdown of a location as [container id => pieces (float)], zero rows left out.
     *
     * @return array<int, float>
     */
    function rvBreakdownOf(Ingredient $ingredient, ?Branch $branch): array
    {
        return DB::table('pos_stock_container_balances')
            ->where('ingredient_id', $ingredient->id)
            ->when($branch === null, static fn ($q) => $q->whereNull('branch_id'), static fn ($q) => $q->where('branch_id', $branch->id))
            ->where('pieces', '>', 0)
            ->pluck('pieces', 'container_id')
            ->map(static fn ($p): float => (float) $p)
            ->all();
    }

    /** A second branch of the company. */
    function rvBranch(Company $company, string $name = 'Second'): Branch
    {
        return Branch::factory()->for($company, 'company')->create(['name' => $name]);
    }
}
