<?php

declare(strict_types=1);

/**
 * LAUNCH packaging add-on, part B4 — the test schema mirrors pos_admin
 * 2026_10_06_110001..110004 (work order §3 Part B4): order_types (default 15)
 * on the three line tables and pos_addons; the (item, line) uniques became
 * one partial unique per tick bit; pos_order_packaging_lines; and the
 * order's stock_order_type + packaging_snapshot_json.
 */

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('has the packaging add-on columns and table', function (): void {
    foreach (['pos_product_recipes', 'pos_product_components', 'pos_addon_consumptions', 'pos_addons'] as $table) {
        expect(Schema::hasColumn($table, 'order_types'))->toBeTrue($table);
    }
    expect(Schema::hasColumns('pos_orders', ['stock_order_type', 'packaging_snapshot_json']))->toBeTrue()
        ->and(Schema::hasColumns('pos_order_packaging_lines', [
            'id', 'company_id', 'order_type', 'ingredient_id', 'product_id', 'quantity', 'unit',
            'entered_unit', 'entered_quantity', 'sort_order', 'created_at', 'updated_at', 'deleted_at',
        ]))->toBeTrue();
});

it('defaults every line to every order type, and allows the same item twice only on non-overlapping ticks', function (): void {
    $ctx = makeMerchantActor();
    $latte = pkProduct($ctx['company'], 'Latte');
    $napkin = pkIngredient($ctx['company'], 'Napkin', 'piece');
    $cup = pkItem($ctx['company'], 'Cup');

    pkRecipeLine($latte, $napkin, '1', 1);
    pkRecipeLine($latte, $napkin, '3', 12);
    expect(fn () => pkRecipeLine($latte, $napkin, '2', 8))->toThrow(QueryException::class);

    pkComponent($latte, $cup, '1');
    expect((int) DB::table('pos_product_components')->value('order_types'))->toBe(15)
        ->and(fn () => pkComponent($latte, $cup, '1', 4))->toThrow(QueryException::class);
});

it('keeps one live packaging line per item per type, and frees it once soft-deleted', function (): void {
    $ctx = makeMerchantActor();
    $bag = pkItem($ctx['company'], 'Bag');
    $row = ['company_id' => $ctx['company']->id, 'order_type' => 'to_go', 'product_id' => $bag->id, 'quantity' => '1', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()];

    DB::table('pos_order_packaging_lines')->insert($row);
    DB::table('pos_order_packaging_lines')->insert(['order_type' => 'delivery'] + $row);
    expect(fn () => DB::table('pos_order_packaging_lines')->insert($row))->toThrow(QueryException::class);

    DB::table('pos_order_packaging_lines')->where('order_type', 'to_go')->update(['deleted_at' => now()]);
    DB::table('pos_order_packaging_lines')->insert($row);
    expect(DB::table('pos_order_packaging_lines')->count())->toBe(3);
});
