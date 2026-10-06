<?php

declare(strict_types=1);

/*
 * LAUNCH packaging add-on, part B — fixtures shared by the tests in this
 * folder (required, not a test file). Every helper is prefixed pk so it never
 * collides with the suite's other global helpers. Fixtures write the tables
 * directly (not the models) so they mean the same whatever application code
 * runs: the fail-before run uses base 81a59b4 with the new test schema.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

if (! function_exists('pkProduct')) {
    /** A product row written directly. */
    function pkProduct(Company $company, string $name, string $mode = 'ingredient', array $extra = []): Product
    {
        $id = DB::table('pos_products')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'name' => $name,
            'base_price' => '1.500',
            'stock_mode' => $mode,
            'status' => 'active',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ], $extra));

        return Product::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /** A packaging physical item (cup, lid, napkin, bag). */
    function pkItem(Company $company, string $name, ?string $cost = '0.050', string $purpose = 'packaging'): Product
    {
        return pkProduct($company, $name, 'unit', ['is_internal' => true, 'internal_purpose' => $purpose, 'cost_price' => $cost]);
    }

    function pkIngredient(Company $company, string $name, string $unit = 'g', string $cost = '0.002', array $extra = []): Ingredient
    {
        $id = DB::table('pos_ingredients')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'name' => $name,
            'name_ar' => null,
            'unit' => $unit,
            'default_unit_cost' => $cost,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        return Ingredient::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /** A recipe line written directly (mask = the "Used for" ticks; null = the column default). */
    function pkRecipeLine(Product $product, Ingredient $ingredient, string $qty, ?int $mask = null, int $sort = 0): void
    {
        DB::table('pos_product_recipes')->insert(array_filter([
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => $qty,
            'unit_at_set' => $ingredient->unit?->value ?? 'g',
            'sort_order' => $sort,
            'order_types' => $mask,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn ($v) => $v !== null));
    }

    function pkComponent(Product $product, Product $component, string $qty, ?int $mask = null): void
    {
        DB::table('pos_product_components')->insert(array_filter([
            'product_id' => $product->id,
            'component_product_id' => $component->id,
            'quantity' => $qty,
            'order_types' => $mask,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn ($v) => $v !== null));
    }

    /** A merchant user holding a custom role with exactly these permissions. */
    function pkActorWith(array $permissions): array
    {
        $ctx = makeMerchantActor(MerchantRole::Viewer->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
        $role = Role::query()->create(['name' => 'Custom '.uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['company']->id]);
        $role->syncPermissions($permissions);
        $ctx['user']->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $ctx;
    }

    /** The newest audit row of an event: [old, new]. */
    function pkAudit(string $event, ?int $auditableId = null): ?array
    {
        $row = DB::table('pos_audit_logs')->where('event', $event)
            ->when($auditableId !== null, fn ($q) => $q->where('auditable_id', $auditableId))
            ->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }

        return [
            'old' => is_string($row->old_values) ? json_decode($row->old_values, true) : (array) $row->old_values,
            'new' => is_string($row->new_values) ? json_decode($row->new_values, true) : (array) $row->new_values,
        ];
    }

    /** Masks of a product's recipe lines: [ingredient id => [masks…]] ordered by sort. */
    function pkRecipeMasks(Product $product): array
    {
        $out = [];
        foreach (DB::table('pos_product_recipes')->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get() as $row) {
            $out[(int) $row->ingredient_id][] = (int) $row->order_types;
        }

        return $out;
    }

    function pkTouchedRecently(Product $product): bool
    {
        return (string) DB::table('pos_products')->where('id', $product->id)->value('updated_at') > now()->subHour()->toDateTimeString();
    }
}
