<?php

declare(strict_types=1);

/*
 * LAUNCH review add-on, part C (menu) — fixtures shared by the tests in this
 * folder (required, not a test file). Every helper is prefixed rvm so it never
 * collides with the suite's other global helpers. Fixtures write the tables
 * directly (not the models) so they mean the same whatever application code
 * runs: the fail-before run uses base ef791f0 with the new test schema.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

if (! function_exists('rvmProduct')) {
    /** A product row written directly (new columns included). */
    function rvmProduct(Company $company, string $name, string $price = '1.000', array $extra = []): Product
    {
        $id = DB::table('pos_products')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'name' => $name,
            'base_price' => $price,
            'stock_mode' => 'untracked',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        return Product::query()->withoutGlobalScopes()->findOrFail($id);
    }

    function rvmIngredient(Company $company, string $name, ?string $nameAr = null, string $unit = 'g'): Ingredient
    {
        $id = DB::table('pos_ingredients')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'name' => $name,
            'name_ar' => $nameAr,
            'unit' => $unit,
            'default_unit_cost' => '0.002',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Ingredient::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * A made-to-order burger with a recipe: ketchup, onion, bun.
     *
     * @return array{burger: Product, ketchup: Ingredient, onion: Ingredient, bun: Ingredient}
     */
    function rvmBurger(Company $company, string $mode = 'ingredient'): array
    {
        $burger = rvmProduct($company, 'Burger', '2.000', ['stock_mode' => $mode]);
        $ketchup = rvmIngredient($company, 'Ketchup (Heinz 5 kg)', 'كاتشب');
        $onion = rvmIngredient($company, 'Onion', 'بصل');
        $bun = rvmIngredient($company, 'Bun', null, 'piece');
        foreach ([[$ketchup, '20'], [$onion, '15'], [$bun, '1']] as $i => [$ingredient, $qty]) {
            DB::table('pos_product_recipes')->insert([
                'product_id' => $burger->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => $qty,
                'unit_at_set' => $ingredient->unit?->value ?? 'g',
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['burger' => $burger, 'ketchup' => $ketchup, 'onion' => $onion, 'bun' => $bun];
    }

    /** A merchant user holding a custom role with exactly these permissions. */
    function rvmActorWith(array $permissions): array
    {
        $ctx = makeMerchantActor(MerchantRole::Viewer->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
        $role = Role::query()->create(['name' => 'Custom '.uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['company']->id]);
        $role->syncPermissions($permissions);
        $ctx['user']->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $ctx;
    }

    /** @return array{burger: Product, chicken: Product, fries: Product, salad: Product, cola: Product} */
    function rvmComboItems(Company $company): array
    {
        return [
            'burger' => rvmProduct($company, 'Beef burger', '2.000'),
            'chicken' => rvmProduct($company, 'Chicken burger', '1.800'),
            'fries' => rvmProduct($company, 'Fries', '0.700'),
            'salad' => rvmProduct($company, 'Salad', '0.900'),
            'cola' => rvmProduct($company, 'Cola', '0.400'),
        ];
    }

    /** @param array<string, Product> $items */
    function rvmComboPayload(array $items, array $extra = []): array
    {
        return array_merge([
            'name' => 'Burger meal',
            'name_ar' => 'وجبة برجر',
            'base_price' => '3.500',
            'delivery_price' => null,
            'sold_in_store' => true,
            'show_on_customer_tablet' => true,
            'sold_on_delivery' => true,
            // LAUNCH combo add-on — lines: the burger (upgrade: chicken) and
            // the fries (upgrade: salad +0.250), both included.
            'lines' => [
                ['kind' => 'fixed', 'product_uuid' => $items['burger']->uuid, 'quantity' => 1, 'upgrades' => [
                    ['product_uuid' => $items['chicken']->uuid, 'upgrade_price' => '0'],
                ]],
                ['kind' => 'fixed', 'product_uuid' => $items['fries']->uuid, 'quantity' => 1, 'upgrades' => [
                    ['product_uuid' => $items['salad']->uuid, 'upgrade_price' => '0.250'],
                ]],
            ],
            'delivery_prices' => [],
            'branches' => null,
        ], $extra);
    }

    /**
     * Save "Can be removed" ticks the way the wizard does (fix order C-1, M1):
     * with the ticks the page loaded (`expected`), read here with a GET unless
     * given.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>|null  $expected
     */
    function rvmTick(Product $product, array $lines, ?array $expected = null): TestResponse
    {
        if ($expected === null) {
            $expected = array_map(
                static fn (array $l): array => ['ingredient_uuid' => $l['ingredient_uuid'], 'label' => $l['label'] ?? null, 'label_ar' => $l['label_ar'] ?? null],
                (array) (test()->getJson("/api/products/{$product->uuid}/removable")->json('data.lines') ?? []),
            );
        }

        return test()->putJson("/api/products/{$product->uuid}/removable", ['lines' => $lines, 'expected' => $expected]);
    }

    /** The newest audit row of an event for a row, decoded. */
    function rvmAudit(string $event, int $auditableId): ?array
    {
        $row = DB::table('pos_audit_logs')->where('event', $event)->where('auditable_id', $auditableId)->orderByDesc('id')->first();

        return $row === null ? null : [
            'old' => json_decode((string) $row->old_values, true),
            'new' => json_decode((string) $row->new_values, true),
        ];
    }
}
