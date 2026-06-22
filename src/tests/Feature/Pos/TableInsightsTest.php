<?php

declare(strict_types=1);

/**
 * Dine-in Table Insights (v2).
 *
 *   GET /api/table-insights?branch_id=        — per-table overview (reports.view)
 *   GET /api/table-insights/{table:uuid}      — one table's full record (reports.view)
 *
 * Aggregates over pos_orders.table_id: sittings (paid orders), spend,
 * opened_at→closed_at duration, distinct customers, live occupancy.
 * Tenant-scoped (cross-tenant id = 404).
 */

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Floor;
use App\Models\Order;
use App\Models\PosStaff;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns a per-table overview with sittings, spend, duration + live occupancy', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create(['name' => 'Main']);
    $t1 = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'T1', 'seats' => 4]);
    $t2 = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'T2', 'seats' => 2]);

    $alice = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);

    // Two paid sittings at T1 (same customer).
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $t1->id, 'customer_id' => $alice->id, 'grand_total' => '20.000',
        'opened_at' => Carbon::parse('2026-06-15 10:00:00'), 'closed_at' => Carbon::parse('2026-06-15 11:00:00'),
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $t1->id, 'customer_id' => $alice->id, 'grand_total' => '10.000',
        'opened_at' => Carbon::parse('2026-06-14 10:00:00'), 'closed_at' => Carbon::parse('2026-06-14 10:30:00'),
    ]);
    // An OPEN order at T2 — occupies the table NOW, but is not a completed sitting.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'status' => 'open', 'table_id' => $t2->id, 'grand_total' => '5.000', 'opened_at' => Carbon::now(),
    ]);

    $data = $this->getJson("/api/table-insights?branch_id={$ctx['branch']->id}")->assertOk()->json('data');

    expect($data['totals']['table_count'])->toBe(2);
    expect($data['totals']['sittings'])->toBe(2);
    expect($data['totals']['revenue'])->toBe('30.000');
    expect($data['totals']['occupied_now'])->toBe(1);

    $byLabel = collect($data['tables'])->keyBy('label');
    expect($byLabel['T1']['sittings'])->toBe(2);
    expect($byLabel['T1']['revenue'])->toBe('30.000');
    expect($byLabel['T1']['avg_spend'])->toBe('15.000');
    expect($byLabel['T1']['unique_customers'])->toBe(1);
    // 3600s + 1800s → total 5400, avg 2700.
    expect($byLabel['T1']['total_duration_seconds'])->toBe(5400);
    expect($byLabel['T1']['avg_duration_seconds'])->toBe(2700);
    expect($byLabel['T1']['active_now'])->toBeFalse();
    expect($byLabel['T1']['floor_name'])->toBe('Main');

    // T2: no paid sittings, but occupied right now.
    expect($byLabel['T2']['sittings'])->toBe(0);
    expect($byLabel['T2']['revenue'])->toBe('0.000');
    expect($byLabel['T2']['active_now'])->toBeTrue();
});

it('returns one table full record: KPIs, sittings list, top customers, busiest hour', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create(['name' => 'Patio']);
    $table = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'P3', 'seats' => 6]);
    $staff = PosStaff::factory()->for($ctx['company'], 'company')->create(['branch_id' => $ctx['branch']->id, 'name' => 'Sam']);
    $alice = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);
    $bob = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Bob']);

    // Alice: two sittings (60 total). Bob: one (10).
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $table->id, 'customer_id' => $alice->id, 'staff_id' => $staff->id, 'grand_total' => '40.000',
        'opened_at' => Carbon::parse('2026-06-15 14:00:00'), 'closed_at' => Carbon::parse('2026-06-15 15:00:00'),
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $table->id, 'customer_id' => $alice->id, 'grand_total' => '20.000',
        'opened_at' => Carbon::parse('2026-06-10 14:00:00'), 'closed_at' => Carbon::parse('2026-06-10 14:40:00'),
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $table->id, 'customer_id' => $bob->id, 'grand_total' => '10.000',
        'opened_at' => Carbon::parse('2026-06-12 09:00:00'), 'closed_at' => Carbon::parse('2026-06-12 09:20:00'),
    ]);

    $data = $this->getJson("/api/table-insights/{$table->uuid}")->assertOk()->json('data');

    expect($data['table']['label'])->toBe('P3');
    expect($data['table']['floor_name'])->toBe('Patio');
    expect($data['table']['branch_name'])->toBe($ctx['branch']->name);

    expect($data['summary']['sittings'])->toBe(3);
    expect($data['summary']['revenue'])->toBe('70.000');
    expect($data['summary']['avg_spend'])->toBe('23.333'); // 70 / 3
    expect($data['summary']['unique_customers'])->toBe(2);
    // 3600 + 2400 + 1200 → avg 2400.
    expect($data['summary']['avg_duration_seconds'])->toBe(2400);
    expect($data['summary']['busiest_hour'])->toBe(14); // 14:00 has two sittings
    expect($data['summary']['active_now'])->toBeFalse();

    // Sittings list newest-first, with relations.
    expect($data['sittings'])->toHaveCount(3);
    expect($data['sittings'][0]['customer_name'])->toBe('Alice');
    expect($data['sittings'][0]['staff_name'])->toBe('Sam');
    expect($data['sittings'][0]['duration_seconds'])->toBe(3600);

    // Top customers by spend: Alice (60, 2 visits) before Bob (10).
    expect($data['top_customers'][0]['name'])->toBe('Alice');
    expect($data['top_customers'][0]['visits'])->toBe(2);
    expect($data['top_customers'][0]['spend'])->toBe('60.000');
    expect($data['top_customers'][1]['name'])->toBe('Bob');
});

it('excludes orders from other tables and only counts paid sittings', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $table = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'A1']);
    $other = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'A2']);

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $table->id, 'grand_total' => '12.000',
        'opened_at' => Carbon::parse('2026-06-15 10:00:00'), 'closed_at' => Carbon::parse('2026-06-15 10:30:00'),
    ]);
    // A paid sitting at a DIFFERENT table — must not leak in.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $other->id, 'grand_total' => '99.000', 'opened_at' => Carbon::now(),
    ]);
    // A HELD order at our table — occupies it now, isn't a sitting.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'status' => 'held', 'table_id' => $table->id, 'grand_total' => '7.000', 'opened_at' => Carbon::now(),
    ]);

    $data = $this->getJson("/api/table-insights/{$table->uuid}")->assertOk()->json('data');

    expect($data['summary']['sittings'])->toBe(1);
    expect($data['summary']['revenue'])->toBe('12.000');
    expect($data['summary']['active_now'])->toBeTrue();
});

it('keeps sittings on a since-removed table in the branch totals', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $kept = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'K1']);
    $gone = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'G1']);

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $kept->id, 'grand_total' => '10.000',
        'opened_at' => Carbon::parse('2026-06-15 10:00:00'), 'closed_at' => Carbon::parse('2026-06-15 10:30:00'),
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $gone->id, 'grand_total' => '25.000',
        'opened_at' => Carbon::parse('2026-06-15 11:00:00'), 'closed_at' => Carbon::parse('2026-06-15 11:30:00'),
    ]);

    // The merchant removes G1 from the floor plan (soft delete).
    $gone->delete();

    $data = $this->getJson("/api/table-insights?branch_id={$ctx['branch']->id}")->assertOk()->json('data');

    // Per-table rows list only the surviving table...
    expect($data['tables'])->toHaveCount(1);
    expect($data['tables'][0]['label'])->toBe('K1');
    // ...but the branch totals still count BOTH sittings, so they reconcile
    // with the Sales report after a floor reshuffle.
    expect($data['totals']['sittings'])->toBe(2);
    expect($data['totals']['revenue'])->toBe('35.000');
});

it('does not leak another tenant table or branch (404)', function (): void {
    $ctx = makeMerchantActor();

    $foreignTable = Table::factory()->create();       // different company
    $foreignBranch = Branch::factory()->create();     // different company

    $this->getJson("/api/table-insights/{$foreignTable->uuid}")->assertNotFound();
    $this->getJson("/api/table-insights?branch_id={$foreignBranch->id}")->assertNotFound();
});

it('gates both endpoints behind reports.view', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $table = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create();

    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson("/api/table-insights?branch_id={$ctx['branch']->id}")->assertForbidden();
    $this->getJson("/api/table-insights/{$table->uuid}")->assertForbidden();
});

it('shows a joined order under every table it covered, counting branch totals once', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $a = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'A1']);
    $b = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'B2']);

    // One shared order billed on A1 (primary), covering B2 (a joined party).
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $a->id, 'grand_total' => '40.000',
        'opened_at' => Carbon::parse('2026-06-15 13:00:00'), 'closed_at' => Carbon::parse('2026-06-15 14:00:00'),
    ]);
    DB::table('pos_order_tables')->insert(['order_id' => $order->id, 'table_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);

    $data = $this->getJson("/api/table-insights?branch_id={$ctx['branch']->id}")->assertOk()->json('data');

    $byLabel = collect($data['tables'])->keyBy('label');
    // The order shows in FULL under BOTH tables (the user's chosen attribution).
    expect($byLabel['A1']['sittings'])->toBe(1);
    expect($byLabel['A1']['revenue'])->toBe('40.000');
    expect($byLabel['B2']['sittings'])->toBe(1);
    expect($byLabel['B2']['revenue'])->toBe('40.000');
    // ...but the branch totals count the single sale ONCE (no double-count).
    expect($data['totals']['sittings'])->toBe(1);
    expect($data['totals']['revenue'])->toBe('40.000');
});

it('tags a joined sitting with the other covered tables in the detail', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $a = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'A1']);
    $b = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'B2']);

    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'table_id' => $a->id, 'grand_total' => '40.000',
        'opened_at' => Carbon::parse('2026-06-15 13:00:00'), 'closed_at' => Carbon::parse('2026-06-15 14:00:00'),
    ]);
    DB::table('pos_order_tables')->insert(['order_id' => $order->id, 'table_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);

    // Viewing B2 (a JOINED, non-primary table): the sitting appears, tagged
    // "joined with A1" (the OTHER covered table — here the primary).
    $data = $this->getJson("/api/table-insights/{$b->uuid}")->assertOk()->json('data');
    expect($data['summary']['sittings'])->toBe(1);
    expect($data['summary']['joined_sittings'])->toBe(1);
    expect($data['sittings'])->toHaveCount(1);
    expect($data['sittings'][0]['joined'])->toBeTrue();
    expect($data['sittings'][0]['joined_tables'])->toBe(['A1']);
    expect($data['sittings'][0]['grand_total'])->toBe('40.000');

    // Viewing the PRIMARY A1: same sitting, tagged "joined with B2".
    $dataA = $this->getJson("/api/table-insights/{$a->uuid}")->assertOk()->json('data');
    expect($dataA['sittings'][0]['joined_tables'])->toBe(['B2']);
});

it('lights up a joined seat as occupied in the overview during a live joined order', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $a = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'A1']);
    $b = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create(['label' => 'B2']);

    // A LIVE (held) joined order: primary A1, covering B2.
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'status' => 'held', 'table_id' => $a->id, 'grand_total' => '15.000', 'opened_at' => now(),
    ]);
    DB::table('pos_order_tables')->insert(['order_id' => $order->id, 'table_id' => $b->id, 'created_at' => now(), 'updated_at' => now()]);

    $data = $this->getJson("/api/table-insights?branch_id={$ctx['branch']->id}")->assertOk()->json('data');

    $byLabel = collect($data['tables'])->keyBy('label');
    expect($byLabel['A1']['active_now'])->toBeTrue(); // primary occupied
    expect($byLabel['B2']['active_now'])->toBeTrue(); // the joined seat lights up too
    expect($data['totals']['occupied_now'])->toBe(2);
});
