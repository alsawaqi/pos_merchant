<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part C6 — the test schema mirrors pos_admin's
 * migrations 09 and 10 (work order §3.1): the new columns with their
 * defaults, and the contract's CHECKs and the one-main unique index, so the
 * portal's tests meet the same refusals as Postgres.
 * Before: none of these columns existed in the test schema.
 */

use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('has the menu columns of migrations 09 and 10', function (): void {
    expect(Schema::hasColumns('pos_products', ['on_sale_from', 'on_sale_until', 'cooking_minutes']))->toBeTrue()
        ->and(Schema::hasColumn('pos_combo_slots', 'is_main'))->toBeTrue()
        ->and(Schema::hasColumn('pos_order_items', 'cooking_minutes'))->toBeTrue()
        ->and(Schema::hasColumn('pos_addon_groups', 'kind'))->toBeTrue()
        ->and(Schema::hasColumn('pos_addons', 'removes_ingredient_id'))->toBeTrue();
});

it('defaults every existing row to no change: not main, extras, no dates, no cooking time', function (): void {
    $company = Company::factory()->create();
    $combo = rvmProduct($company, 'Meal', '3.000', ['product_type' => 'combo']);
    $slot = DB::table('pos_combo_slots')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'combo_product_id' => $combo->id, 'name' => 'Main']);
    $group = DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Sauces']);

    expect((int) DB::table('pos_combo_slots')->where('id', $slot)->value('is_main'))->toBe(0)
        ->and(DB::table('pos_addon_groups')->where('id', $group)->value('kind'))->toBe('extras')
        ->and(DB::table('pos_products')->where('id', $combo->id)->value('on_sale_from'))->toBeNull()
        ->and(DB::table('pos_products')->where('id', $combo->id)->value('cooking_minutes'))->toBeNull();
});

it('refuses what the contract refuses: two mains, until before from, cooking outside 0..240, an unknown kind', function (): void {
    $company = Company::factory()->create();
    $combo = rvmProduct($company, 'Meal', '3.000', ['product_type' => 'combo']);
    $slot = fn (string $name, int $main): array => ['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'combo_product_id' => $combo->id, 'name' => $name, 'is_main' => $main];
    DB::table('pos_combo_slots')->insert($slot('Burger', 1));
    DB::table('pos_combo_slots')->insert($slot('Side', 0));

    $refused = function (callable $write): bool {
        try {
            $write();
        } catch (QueryException) {
            return true;
        }

        return false;
    };

    expect($refused(fn () => DB::table('pos_combo_slots')->insert($slot('Drink', 1))))->toBeTrue()
        ->and($refused(fn () => rvmProduct($company, 'Bad dates', '1', ['on_sale_from' => '2026-11-10', 'on_sale_until' => '2026-11-09'])))->toBeTrue()
        ->and($refused(fn () => rvmProduct($company, 'Too long', '1', ['cooking_minutes' => 241])))->toBeTrue()
        ->and($refused(fn () => rvmProduct($company, 'Negative', '1', ['cooking_minutes' => -1])))->toBeTrue()
        ->and($refused(fn () => DB::table('pos_addon_groups')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'X', 'kind' => 'surprise'])))->toBeTrue()
        ->and($refused(fn () => rvmProduct($company, 'One day', '1', ['on_sale_from' => '2026-11-10', 'on_sale_until' => '2026-11-10', 'cooking_minutes' => 240])))->toBeFalse()
        ->and($refused(fn () => DB::table('pos_addon_groups')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Y', 'kind' => 'instructions'])))->toBeFalse();
});
