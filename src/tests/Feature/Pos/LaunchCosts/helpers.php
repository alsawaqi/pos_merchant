<?php

declare(strict_types=1);

/*
 * LAUNCH costs & allergens add-on test fixtures shared by the tests in this
 * folder (required, not a test file). Every helper is prefixed lc. Rows are
 * written straight to the tables so a fixture means the same whatever
 * application code runs (the fail-before run uses the base commit).
 */

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../LaunchP3/helpers.php';
require_once __DIR__.'/../LaunchP4/helpers.php';

if (! function_exists('lcRecipe')) {
    /**
     * A product's recipe lines: [[Ingredient, base quantity, mask?], ...].
     *
     * @param  list<array{0: Ingredient, 1: string, 2?: int}>  $lines
     */
    function lcRecipe(Product $product, array $lines): void
    {
        foreach ($lines as $i => $line) {
            DB::table('pos_product_recipes')->insert([
                'product_id' => $product->id, 'ingredient_id' => $line[0]->id, 'quantity' => $line[1],
                'unit_at_set' => $line[0]->unit?->value ?? 'g', 'sort_order' => $i, 'order_types' => $line[2] ?? 15,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** One allergen tick on an ingredient / prep item. */
    function lcTag(Ingredient $ingredient, string ...$allergens): void
    {
        foreach ($allergens as $allergen) {
            DB::table('pos_ingredient_allergens')->insert([
                'company_id' => $ingredient->company_id, 'ingredient_id' => $ingredient->id, 'allergen' => $allergen,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Ticks set by hand on a product ('contains' | 'may_contain'). */
    function lcOwn(Product $product, string $kind, string ...$allergens): void
    {
        foreach ($allergens as $allergen) {
            DB::table('pos_product_allergens')->insert([
                'company_id' => $product->company_id, 'product_id' => $product->id, 'allergen' => $allergen, 'kind' => $kind,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** A combo / meal line (owner = ['combo_product_id' => id] or ['meal_id' => id]). Returns its id. */
    function lcLine(Company $company, array $owner, array $line, array $upgrades = [], array $excluded = []): int
    {
        $id = (int) DB::table('pos_combo_lines')->insertGetId($owner + $line + [
            'company_id' => $company->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($upgrades as $productId => $price) {
            DB::table('pos_combo_line_upgrades')->insert(['company_id' => $company->id, 'line_id' => $id, 'product_id' => $productId,
                'upgrade_price' => $price, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($excluded as $productId) {
            DB::table('pos_combo_line_items')->insert(['company_id' => $company->id, 'line_id' => $id, 'product_id' => $productId,
                'excluded' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    /** An active meal on the given categories. Returns its id. */
    function lcMeal(Company $company, string $name, string $price, array $categoryIds): int
    {
        $id = (int) DB::table('pos_meals')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => $name, 'name_ar' => 'وجبة',
            'meal_price' => $price, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($categoryIds as $categoryId) {
            DB::table('pos_meal_categories')->insert(['company_id' => $company->id, 'meal_id' => $id, 'category_id' => $categoryId,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    function lcCategory(Company $company, string $name): int
    {
        return (int) DB::table('pos_product_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => $name, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Act as another user of the same merchant holding exactly these
     * permissions.
     *
     * @param  array{company: Company}  $ctx
     * @param  list<string>  $permissions
     */
    function lcActAs(array $ctx, array $permissions): User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($ctx['company']->id);
        foreach ($permissions as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $registrar->forgetCachedPermissions();
        $role = Role::query()->create(['name' => 'Custom '.uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['company']->id]);
        $role->syncPermissions($permissions);
        /** @var User $user */
        $user = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant', 'status' => 'active']);
        $user->syncRoles([$role]);
        $registrar->forgetCachedPermissions();
        app('auth')->forgetGuards();
        test()->actingAs($user);

        return $user;
    }

    /** Record a goods-received note through the API (one ingredient line per row: [Ingredient, base qty, line cost]). */
    function lcReceive(array $rows, ?string $supplierUuid = null, ?string $date = null): array
    {
        return test()->postJson('/api/purchase-receipts', array_filter([
            'supplier_uuid' => $supplierUuid,
            'received_at' => $date,
            'lines' => array_map(static fn (array $r): array => [
                'item_type' => 'ingredient', 'item_uuid' => $r[0]->uuid, 'quantity' => $r[1], 'line_cost' => $r[2],
            ], $rows),
        ], static fn ($v): bool => $v !== null))->assertCreated()->json('data');
    }

    /** A supplier of the merchant. Returns its uuid. */
    function lcSupplier(Company $company, string $name): string
    {
        $uuid = (string) Str::uuid();
        DB::table('pos_suppliers')->insert(['uuid' => $uuid, 'company_id' => $company->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }
}
