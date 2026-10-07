<?php

declare(strict_types=1);

/**
 * LAUNCH costs & allergens add-on, Part A (LAUNCH-COSTS_ALLERGENS_WORK_ORDER.md,
 * tester call 1): the supplier price history of an ingredient, built from
 * the goods-received lines (no new data entry), and the price-change alert —
 * a line whose price per base unit moved from the previous purchase (any
 * supplier) by at least the company threshold (default 10%) — on the
 * purchase confirmation, in a list with the dishes affected (new cost, food
 * cost %, crossed the target) and on a dashboard card; a manager marks one
 * seen (audited). Per merchant, permission-gated.
 *
 *   Tomato, per gram: 40 days ago 0.000500 (supplier A), 10 days ago
 *   0.000540 (+8.0%, supplier B), today 0.000600 (+11.1%, supplier A).
 *   Tomato soup 0.800 = 400 g tomato + 20 g cream (0.001): at the new price
 *   0.260 = 32.5% (over 30), at the old 0.236 = 29.5% — crossed the target.
 */

use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** @return array<string, mixed> */
function lcTomatoes(array $ctx): array
{
    $c = $ctx['company'];
    $k = [
        'tomato' => p3Ingredient($c, 'Tomato', 'g', '0'),
        'cream' => p3Ingredient($c, 'Cream', 'g', '0.001000'),
        'a' => lcSupplier($c, 'Supplier A'),
        'b' => lcSupplier($c, 'Supplier B'),
    ];
    $k['soup'] = p4Product($c, 'Tomato soup', '0.800', ['stock_mode' => 'ingredient']);
    lcRecipe($k['soup'], [[$k['tomato'], '400'], [$k['cream'], '20']]);
    lcReceive([[$k['tomato'], '10000', '5.000']], $k['a'], now()->subDays(40)->toDateString());
    lcReceive([[$k['tomato'], '10000', '5.400']], $k['b'], now()->subDays(10)->toDateString());
    // A line paid nothing carries no price: it is not in the history.
    lcReceive([[$k['tomato'], '500', '0']], null, now()->subDays(5)->toDateString());

    return $k;
}

it('builds the price history from goods received, newest first, with the change from the previous purchase', function (): void {
    $ctx = makeMerchantActor();
    $k = lcTomatoes($ctx);
    lcReceive([[$k['tomato'], '10000', '6.000']], $k['a']);

    $history = $this->getJson('/api/ingredients/'.$k['tomato']->uuid.'/price-history')->assertOk();
    $rows = $history->json('data');
    expect(array_map(static fn (array $r): array => [$r['unit_cost'], $r['previous_unit_cost'], $r['change_pct'], $r['alert'], $r['supplier']['name'] ?? null], $rows))
        ->toEqual([
            ['0.000600', '0.000540', 11.1, true, 'Supplier A'],
            ['0.000540', '0.000500', 8.0, false, 'Supplier B'],
            ['0.000500', null, null, false, 'Supplier A'],
        ])
        ->and($rows[0]['change'])->toBe('0.000060')
        ->and($rows[0]['unit'])->toBe('g')
        ->and($history->json('meta.threshold_percent'))->toEqual(10);
});

it('raises an alert on the purchase confirmation with the dishes affected and whether they crossed the target', function (): void {
    $ctx = makeMerchantActor();
    $k = lcTomatoes($ctx);
    // The +8% purchase was under the 10% threshold: no alert.
    expect($this->getJson('/api/price-alerts')->assertOk()->json('data'))->toBe([]);

    $receipt = lcReceive([[$k['tomato'], '10000', '6.000'], [$k['cream'], '1000', '1.000']], $k['a']);
    expect($receipt['price_alerts'])->toHaveCount(1);
    $alert = $receipt['price_alerts'][0];
    expect([$alert['ingredient']['name'], $alert['old_unit_cost'], $alert['new_unit_cost'], $alert['change_pct'], $alert['supplier']['name'], $alert['seen']])
        ->toEqual(['Tomato', '0.000540', '0.000600', 11.1, 'Supplier A', false])
        ->and($alert['dishes'])->toEqual([[
            'product_uuid' => $k['soup']->uuid, 'name' => 'Tomato soup', 'name_ar' => null, 'product_type' => 'standard',
            'status' => 'ok', 'cost_baisas' => 260, 'food_cost_pct' => 32.5, 'previous_food_cost_pct' => 29.5,
            'target_pct' => 30.0, 'over_target' => true, 'crossed_target' => true,
        ]]);
    // The saved receipt shows it again.
    expect($this->getJson('/api/purchase-receipts/'.$receipt['uuid'])->assertOk()->json('data.price_alerts.0.line_id'))->toBe($alert['line_id']);

    // A price that goes DOWN by the threshold alerts too.
    $down = lcReceive([[$k['tomato'], '10000', '5.000']], $k['b']);
    expect($down['price_alerts'][0]['change_pct'])->toBe(-16.7)
        ->and($down['price_alerts'][0]['dishes'][0]['crossed_target'])->toBeFalse();
});

it('follows the company threshold: exactly the threshold alerts, below does not', function (): void {
    $ctx = makeMerchantActor();
    $k = lcTomatoes($ctx);
    lcReceive([[$k['tomato'], '10000', '6.000']], $k['a']);
    expect($this->getJson('/api/settings/costs')->assertOk()->json('data'))->toBe(['price_alert_threshold_percent' => '10.00', 'target_food_cost_percent' => '30.00']);

    $this->putJson('/api/settings/costs', ['price_alert_threshold_percent' => 8])->assertOk()->assertJsonPath('data.price_alert_threshold_percent', '8.00');
    // +8.0% now alerts (at exactly the threshold) — both lines in the last 30 days.
    expect(array_column($this->getJson('/api/price-alerts')->json('data'), 'change_pct'))->toEqual([11.1, 8.0]);
    $this->putJson('/api/settings/costs', ['price_alert_threshold_percent' => '11.2'])->assertOk();
    expect($this->getJson('/api/price-alerts')->json('data'))->toBe([]);

    $audit = DB::table('pos_audit_logs')->where('event', 'settings.costs.updated')->orderBy('id')->get();
    expect($audit)->toHaveCount(2)
        ->and(json_decode((string) $audit[0]->old_values, true))->toBe(['price_alert_threshold_percent' => '10.00'])
        ->and(json_decode((string) $audit[0]->new_values, true))->toBe(['price_alert_threshold_percent' => '8.00']);
    $this->putJson('/api/settings/costs', ['price_alert_threshold_percent' => 0])->assertUnprocessable();
    $this->putJson('/api/settings/costs', ['target_food_cost_percent' => 101])->assertUnprocessable();
});

it('lists the last 30 days, marks an alert seen once (audited) and counts it on the dashboard', function (): void {
    $ctx = makeMerchantActor();
    $k = lcTomatoes($ctx);
    $receipt = lcReceive([[$k['tomato'], '10000', '6.000']], $k['a']);
    $lineId = $receipt['price_alerts'][0]['line_id'];

    $summary = $this->getJson('/api/dashboard/summary')->assertOk()->json('data.price_alerts');
    expect($summary)->toEqual(['count' => 1, 'unseen' => 1, 'days' => 30, 'threshold_percent' => 10]);

    $this->postJson('/api/price-alerts/'.$lineId.'/seen')->assertOk()->assertJsonPath('data.marked_now', true);
    $this->postJson('/api/price-alerts/'.$lineId.'/seen')->assertOk()->assertJsonPath('data.marked_now', false);
    $list = $this->getJson('/api/price-alerts')->assertOk()->json('data');
    expect($list[0]['seen'])->toBeTrue()->and($list[0]['seen_by'])->toBe($ctx['user']->name)
        ->and($this->getJson('/api/price-alerts?seen=unseen')->json('data'))->toBe([])
        ->and($this->getJson('/api/dashboard/summary')->json('data.price_alerts.unseen'))->toBe(0);
    $audit = DB::table('pos_audit_logs')->where('event', 'inventory.price_alert.seen')->sole();
    expect((int) $audit->auditable_id)->toBe($lineId)
        ->and(json_decode((string) $audit->new_values, true))->toMatchArray(['old_unit_cost' => '0.000540', 'new_unit_cost' => '0.000600', 'change_pct' => 11.1]);

    // A line that is not an alert (the +8% one) cannot be marked.
    $plain = (int) DB::table('pos_purchase_receipt_lines')->where('ingredient_id', $k['tomato']->id)->orderBy('id')->skip(1)->value('id');
    $this->postJson('/api/price-alerts/'.$plain.'/seen')->assertUnprocessable();
    // An alert older than 30 days is not listed: the 0.000600 purchase moved
    // to 41 days ago makes the 40-day-old one the alert (−16.7%), and the
    // 10-day-old one (+8%) is under the threshold.
    DB::table('pos_purchase_receipts')->where('uuid', $receipt['uuid'])->update(['received_at' => now()->subDays(41)]);
    expect($this->getJson('/api/price-alerts')->assertOk()->json('data'))->toBe([])
        ->and(count($this->getJson('/api/price-alerts?days=60')->json('data')))->toBe(1);
});

it('lets a viewer see alerts but only a manager mark them; dishes and costs stay with the cost permission', function (): void {
    $ctx = makeMerchantActor();
    $k = lcTomatoes($ctx);

    // An inventory manager without reports.view records the purchase: the
    // alert shows, its dishes (costs) do not; the list is closed to them.
    lcActAs($ctx, [MerchantPermission::InventoryView->value, MerchantPermission::InventoryManage->value]);
    $receipt = lcReceive([[$k['tomato'], '10000', '6.000']], $k['a']);
    expect($receipt['price_alerts'][0]['change_pct'])->toBe(11.1)->and($receipt['price_alerts'][0]['dishes'])->toBeNull();
    $this->getJson('/api/price-alerts')->assertForbidden();
    $this->getJson('/api/ingredients/'.$k['tomato']->uuid.'/price-history')->assertOk();
    $this->putJson('/api/settings/costs', ['price_alert_threshold_percent' => 12])->assertOk();
    $this->putJson('/api/settings/costs', ['target_food_cost_percent' => 28])->assertForbidden();
    $this->putJson('/api/settings/costs', ['price_alert_threshold_percent' => 10])->assertOk();

    // A viewer (reports.view) reads the list, never marks it seen.
    lcActAs($ctx, [MerchantPermission::ReportsView->value, MerchantPermission::InventoryView->value]);
    $lineId = $this->getJson('/api/price-alerts')->assertOk()->json('data.0.line_id');
    $this->postJson('/api/price-alerts/'.$lineId.'/seen')->assertForbidden();
    $this->putJson('/api/settings/costs', ['price_alert_threshold_percent' => 12])->assertForbidden();
    lcActAs($ctx, [MerchantPermission::CatalogueView->value, MerchantPermission::CatalogueManage->value]);
    $this->putJson('/api/settings/costs', ['target_food_cost_percent' => 28])->assertOk()->assertJsonPath('data.target_food_cost_percent', '28.00');
    $this->getJson('/api/ingredients/'.$k['tomato']->uuid.'/price-history')->assertForbidden();
});

it('never shows or marks another merchant\'s purchases', function (): void {
    $other = makeMerchantActor();
    $o = lcTomatoes($other);
    $theirs = lcReceive([[$o['tomato'], '10000', '6.000']], $o['a']);

    $ctx = makeMerchantActor(MerchantRole::SuperAdmin->value);
    $k = lcTomatoes($ctx);
    // Our settings are ours: a tighter threshold of theirs changes nothing here.
    DB::table('pos_company_settings')->insert(['company_id' => $other['company']->id, 'key' => 'costs.price_alert_threshold_percent', 'value' => json_encode(1), 'created_at' => now(), 'updated_at' => now()]);
    expect($this->getJson('/api/price-alerts')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/dashboard/summary')->json('data.price_alerts.count'))->toBe(0);
    $this->postJson('/api/price-alerts/'.$theirs['price_alerts'][0]['line_id'].'/seen')->assertNotFound();
    $this->getJson('/api/ingredients/'.$o['tomato']->uuid.'/price-history')->assertNotFound();
    expect(DB::table('pos_price_alert_reviews')->count())->toBe(0);
});
