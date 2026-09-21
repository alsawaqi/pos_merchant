<?php

declare(strict_types=1);

use App\Models\Floor;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Table;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Only the disposable merchant test database lacks these existing API-owned
// tables. Mirror the columns used by this read-only report, never migrate runtime.
beforeEach(function (): void {
    Schema::create('pos_sync_events', function ($t): void {
        $t->id();
        $t->unsignedBigInteger('device_id');
        $t->string('event_type');
        $t->string('ack_status');
        $t->json('payload_json');
        $t->json('result_json');
        $t->timestamp('client_timestamp');
        $t->timestamp('server_received_at');
    });
    Schema::create('pos_table_sessions', function ($t): void {
        $t->id();
        $t->uuid('uuid');
        $t->unsignedBigInteger('company_id');
        $t->unsignedBigInteger('branch_id');
        $t->unsignedBigInteger('table_id');
        $t->string('origin');
        $t->string('status');
        $t->timestamp('opened_at');
        $t->timestamp('expires_at');
    });
    Schema::create('pos_table_session_events', function ($t): void {
        $t->id();
        $t->unsignedBigInteger('company_id');
        $t->unsignedBigInteger('branch_id');
        $t->unsignedBigInteger('table_id');
        $t->unsignedBigInteger('table_session_id');
        $t->unsignedBigInteger('device_id')->nullable();
        $t->string('event_type');
        $t->json('payload');
        $t->timestamp('created_at');
    });
});

it('shows prepared and clean table cancellations as a breakdown without adding waste twice', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $table = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'T11 Latte']);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($ingredient, 'ingredient')->create([
        'quantity' => '.320', 'unit_cost_at_time' => '2.000', 'occurred_at' => '2026-09-21 12:00:00']);
    $seat = DB::table('pos_table_sessions')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id, 'table_id' => $table->id, 'origin' => 'staff_till', 'status' => 'closed',
        'opened_at' => '2026-09-21 11:00:00', 'expires_at' => '2026-09-21 17:00:00']);
    foreach ([true, false] as $prepared) {
        DB::table('pos_table_session_events')->insert(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
            'table_id' => $table->id, 'table_session_id' => $seat, 'event_type' => 'round_resolved', 'created_at' => '2026-09-21 12:00:00',
            'payload' => json_encode(['action' => 'line_cancelled', 'client_request_id' => (string) Str::uuid(),
                'product_id' => $product->id, 'addon_ids' => [], 'cancelled_qty' => 2, 'prepared' => $prepared,
                'authorized_by' => 'T11 manager', 'reason' => 'T11 test', 'whole_bill' => true,
                'waste' => ['booked' => $prepared, 'cost_baisas' => $prepared ? 640 : 0, 'ingredients' => []]])]);
    }
    $r = $this->getJson('/api/reports/loss-waste?date_from=2026-09-21&date_to=2026-09-21')->assertOk();
    $r->assertJsonPath('data.headline.total_value', '0.640')
        ->assertJsonCount(2, 'data.table_cancellations.rows')
        ->assertJsonPath('data.table_cancellations.cost_baisas', 640)
        ->assertJsonPath('data.table_cancellations.quantity', 4)
        ->assertJsonPath('data.table_cancellations.rows.0.authorized_by', 'T11 manager')
        ->assertJsonPath('data.table_cancellations.rows.0.product_name', 'T11 Latte')
        ->assertJsonPath('data.table_cancellations.rows.0.whole_bill', true)
        ->assertJsonPath('data.table_cancellations.rows.1.cost_baisas', 0);
    $this->getJson('/api/reports/loss-waste?date_from=2026-09-20&date_to=2026-09-20')->assertOk()
        ->assertJsonCount(0, 'data.table_cancellations.rows');
    makeMerchantActor();
    $this->getJson('/api/reports/loss-waste?date_from=2026-09-21&date_to=2026-09-21')->assertOk()
        ->assertJsonCount(0, 'data.table_cancellations.rows');
});

it('matches frozen shelf waste once and flags ambiguous or missing movement evidence', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $table = Table::factory()->for($ctx['company'], 'company')->for($floor, 'floor')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'T11 shelf', 'cost_price' => 99]);
    $seat = DB::table('pos_table_sessions')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id, 'table_id' => $table->id, 'origin' => 'staff_till', 'status' => 'closed',
        'opened_at' => '2026-09-21 11:00:00', 'expires_at' => '2026-09-21 17:00:00']);
    foreach (['exact', 'ambiguous', 'missing'] as $kind) {
        $id = (string) Str::uuid();
        DB::table('pos_table_session_events')->insert(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
            'table_id' => $table->id, 'table_session_id' => $seat, 'device_id' => 700, 'event_type' => 'round_resolved', 'created_at' => '2026-09-21 12:00:00',
            'payload' => json_encode(['action' => 'line_cancelled', 'client_request_id' => $id, 'product_id' => $product->id,
                'cancelled_qty' => 2, 'prepared' => true, 'reason' => $kind, 'waste' => ['booked' => false, 'cost_baisas' => 0]])]);
        if ($kind === 'missing') {
            continue;
        }
        DB::table('pos_sync_events')->insert(['device_id' => 700, 'event_type' => 'product.waste', 'ack_status' => 'processed',
            'client_timestamp' => '2026-09-21 12:01:00', 'server_received_at' => '2026-09-21 12:01:00',
            'payload_json' => json_encode(['note' => $kind]), 'result_json' => json_encode(['table_cancellation_waste' => [
                'request_id' => $id, 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'quantity' => 2]])]);
        for ($i = 0; $i < ($kind === 'exact' ? 1 : 2); $i++) {
            DB::table('pos_product_stock_movements')->insert(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
                'product_id' => $product->id, 'movement_type' => 'waste', 'reason' => 'other', 'quantity' => -2,
                'unit_cost' => .200, 'note' => $kind, 'occurred_at' => '2026-09-21 12:01:00', 'created_at' => '2026-09-21 12:01:00']);
        }
    }
    $this->getJson('/api/reports/loss-waste?date_from=2026-09-21&date_to=2026-09-21')->assertOk()
        ->assertJsonPath('data.headline.total_value', '0.000')
        ->assertJsonPath('data.product_dispositions.0.value', '1.200')
        ->assertJsonPath('data.table_cancellations.cost_baisas', 400)
        ->assertJsonPath('data.table_cancellations.rows.0.cost_baisas', 400)
        ->assertJsonPath('data.table_cancellations.rows.0.cost_matched', true)
        ->assertJsonPath('data.table_cancellations.rows.1.cost_matched', false)
        ->assertJsonPath('data.table_cancellations.rows.2.cost_matched', false);
});
