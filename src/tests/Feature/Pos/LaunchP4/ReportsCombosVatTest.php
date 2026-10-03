<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B8 — reports with combos and VAT-inclusive orders.
 *
 *   - A combo line is its own product; the items chosen in it are child lines
 *     (unit price and line total 0). Every revenue query leaves the children
 *     out; Product performance adds an "Inside combos" quantity; the COGS of a
 *     combo is its children's cost (never a cost price set on the combo).
 *   - On an order with prices_include_tax, sales are reported excluding VAT
 *     (net = grand − tax; a line gives up its share of the VAT).
 *   - Order detail nests the combo items and says the VAT is in the prices;
 *     item counts count the combo once. Exports carry the same numbers.
 *
 * Before: children counted as products (qty, top lists, item counts), the
 * combo's own cost price was added, and inclusive orders reported VAT as
 * sales.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/**
 * Two paid orders: an exclusive one with 2 × "Burger meal" (a combo: burger +
 * fries + cola each) plus 1 standalone fries, and an inclusive one with one
 * burger at 2.100 (VAT 0.100 inside).
 *
 * @return array<string, mixed>
 */
function p4ComboSales(): array
{
    $ctx = makeMerchantActor();
    $company = $ctx['company'];
    $burger = p4Product($company, 'Burger', '2.100', ['cost_price' => '0.800']);
    $fries = p4Product($company, 'Fries', '0.700', ['cost_price' => '0.200']);
    $cola = p4Product($company, 'Cola', '0.400', ['cost_price' => '0.100']);
    // A cost price on the combo itself must never count.
    $meal = p4Product($company, 'Burger meal', '3.500', ['product_type' => 'combo', 'cost_price' => '9.999']);

    [$exclusive, $lines] = p4PaidOrder($ctx['branch'], [
        'subtotal' => '7.700', 'tax_total' => '0.385', 'grand_total' => '8.085',
    ], [
        [$meal, '2.000', '3.500', '7.000'],
        [$fries, '1.000', '0.700', '0.700'],
    ]);
    foreach ([$burger, $fries, $cola] as $item) {
        DB::table('pos_order_items')->insert([
            'order_id' => $exclusive, 'product_id' => $item->id, 'product_name_snapshot' => $item->name,
            'qty' => '2.000', 'unit_price_snapshot' => '0', 'line_discount' => '0', 'line_total' => '0',
            'status' => 'served', 'parent_order_item_id' => $lines[0], 'combo_slot_id' => 1, 'combo_extra_price' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    [$inclusive] = p4PaidOrder($ctx['branch'], [
        'subtotal' => '2.100', 'tax_total' => '0.100', 'grand_total' => '2.100', 'prices_include_tax' => true,
    ], [
        [$burger, '1.000', '2.100', '2.100'],
    ]);

    return $ctx + ['exclusive' => $exclusive, 'inclusive' => $inclusive, 'meal' => $meal];
}

function p4Window(): string
{
    return 'date_from='.now()->subDays(2)->toDateString().'&date_to='.now()->addDay()->toDateString();
}

it('reports sales excluding VAT and counts the combo cost from its items only', function (): void {
    p4ComboSales();

    $headline = $this->getJson('/api/reports/sales?'.p4Window())->assertOk()->json('data.headline');

    expect($headline['gross_sales'])->toBe('9.700')      // 7.700 + (2.100 − 0.100)
        ->and($headline['net_sales'])->toBe('9.700')
        ->and($headline['tax_total'])->toBe('0.485')
        ->and($headline['vat_inclusive_orders'])->toBe(1)
        // 2 × (0.800 + 0.200 + 0.100) inside the combos + fries 0.200 + burger 0.800.
        ->and($headline['cogs'])->toBe('3.200');
});

it('shows the combo as its own product with its items counted inside combos, never as revenue', function (): void {
    p4ComboSales();

    $data = $this->getJson('/api/reports/product-performance?'.p4Window())->assertOk()->json('data');
    $rows = collect($data['top_by_revenue'])->merge($data['slow_movers'])->keyBy('product_name');

    expect($rows['Burger meal'])->toMatchArray([
        'product_type' => 'combo', 'qty_sold' => '2.000', 'revenue' => '7.000', 'recipe_cost' => '2.200', 'inside_combos_qty' => '0.000',
    ]);
    // The inclusive burger: 2.100 less its VAT.
    expect($rows['Burger'])->toMatchArray(['qty_sold' => '1.000', 'revenue' => '2.000', 'inside_combos_qty' => '2.000', 'recipe_cost' => '0.800'])
        ->and($rows['Fries'])->toMatchArray(['qty_sold' => '1.000', 'revenue' => '0.700', 'inside_combos_qty' => '2.000'])
        // Sold only inside combos: a row with no revenue.
        ->and($rows['Cola'])->toMatchArray(['qty_sold' => '0.000', 'revenue' => '0.000', 'inside_combos_qty' => '2.000']);
});

it('keeps combo items out of the dashboard and branch top lists', function (): void {
    $ctx = p4ComboSales();

    $top = collect($this->getJson('/api/dashboard/summary')->assertOk()->json('data.top_products'))->keyBy('product_name');
    expect($top->keys()->sort()->values()->all())->toBe(['Burger', 'Burger meal', 'Fries'])
        ->and($top['Burger']['revenue'])->toBe('2.000')
        ->and($top['Burger meal']['revenue'])->toBe('7.000');

    $branchTop = collect($this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/activity")->assertOk()->json('data.top_products'))->keyBy('product_name');
    expect($branchTop->keys()->sort()->values()->all())->toBe(['Burger', 'Burger meal', 'Fries'])
        ->and($branchTop['Fries']['qty_sold'])->toBe('1.000');
});

it('reports the discount base excluding VAT', function (): void {
    p4ComboSales();

    $this->getJson('/api/reports/discounts?'.p4Window())
        ->assertOk()
        ->assertJsonPath('data.headline.gross_sales', '9.700');
});

it('lists a combo once with its items under it, and marks VAT inside the prices', function (): void {
    $ctx = p4ComboSales();
    $uuid = DB::table('pos_orders')->where('id', $ctx['exclusive'])->value('uuid');
    $inclusiveUuid = DB::table('pos_orders')->where('id', $ctx['inclusive'])->value('uuid');

    $detail = $this->getJson("/api/orders/{$uuid}")->assertOk()->json('data');
    expect($detail['items'])->toHaveCount(2)
        ->and($detail['items'][0]['product_name'])->toBe('Burger meal')
        ->and(collect($detail['items'][0]['components'])->pluck('product_name')->all())->toBe(['Burger', 'Fries', 'Cola'])
        ->and($detail['order']['totals']['prices_include_tax'])->toBeFalse();
    $this->getJson("/api/orders/{$inclusiveUuid}")->assertOk()->assertJsonPath('data.order.totals.prices_include_tax', true);

    $row = collect($this->getJson('/api/orders?'.p4Window())->assertOk()->json('data.rows'))->firstWhere('uuid', $uuid);
    expect($row['items_count'])->toBe(2)->and($row['prices_include_tax'])->toBeFalse();
});

it('exports the same numbers, with the inside-combos column', function (): void {
    p4ComboSales();

    $csv = (string) $this->get('/api/reports/product-performance/export?'.p4Window(), ['Accept' => 'application/json'])->assertOk()->getContent();
    expect($csv)->toContain('inside_combos_qty')
        ->and($csv)->toContain('Burger meal');
    $sales = (string) $this->get('/api/reports/sales/export?'.p4Window(), ['Accept' => 'application/json'])->assertOk()->getContent();
    expect($sales)->toContain('9.700');
});
