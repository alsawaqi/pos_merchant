<?php

declare(strict_types=1);

/**
 * Phase B — Comp Report + Shift Report + Loss/Waste voids sections +
 * the manager shift re-open (Additions §1.2).
 */

use App\Enums\MerchantRole;
use App\Enums\ShiftStatus;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Seed a PAID order with one comp row. Returns the order id.
 */
function seedCompedOrder(array $ctx, array $comp = []): int
{
    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'order_type' => 'dine_in',
        'status' => 'paid',
        'source' => 'main_pos',
        'subtotal' => '8.000',
        'discount_total' => '0.000',
        'comp_total' => '3.000',
        'tax_total' => '0.000',
        'grand_total' => '5.000',
        'opened_at' => '2026-06-05 12:00:00',
        'closed_at' => '2026-06-05 12:05:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pos_order_comps')->insert(array_merge([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'order_id' => $orderId,
        'order_item_id' => null,
        'comp_reason_id' => null,
        'reason_code_snapshot' => 'staff_meal',
        'reason_name_snapshot' => 'Staff Meal',
        'amount' => '3.000',
        'approved_by_pos_staff_id' => null,
        'applied_at' => '2026-06-05 12:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ], $comp));

    return $orderId;
}

// =================== COMP REPORT ===================

it('aggregates comps by reason, branch, staff with a recent drill-down', function (): void {
    $ctx = makeMerchantActor();
    $staff = \App\Models\PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Sara']);

    seedCompedOrder($ctx, ['approved_by_pos_staff_id' => $staff->id]);
    seedCompedOrder($ctx, [
        'reason_code_snapshot' => 'long_wait',
        'reason_name_snapshot' => 'Long Wait',
        'amount' => '1.000',
        'approved_by_pos_staff_id' => $staff->id,
    ]);

    $data = $this->getJson('/api/reports/comps?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data');

    expect($data['headline']['total_value'])->toBe('4.000');
    expect($data['headline']['comp_count'])->toBe(2);
    expect($data['headline']['comped_order_count'])->toBe(2);

    $byReason = collect($data['by_reason']);
    expect($byReason->firstWhere('code', 'staff_meal')['value'])->toBe('3.000');
    expect($byReason->firstWhere('code', 'long_wait')['comp_count'])->toBe(1);

    expect(collect($data['by_staff'])->firstWhere('staff_name', 'Sara')['value'])->toBe('4.000');
    expect($data['recent'])->toHaveCount(2);
    expect($data['by_branch'][0]['value'])->toBe('4.000');
});

it('scopes the comp report to the tenant', function (): void {
    $ctx = makeMerchantActor();
    $other = makeMerchantActor();
    seedCompedOrder($other); // belongs to the OTHER company

    app(\App\Support\MerchantTenantContext::class)->set($ctx['company']->id);
    $this->actingAs($ctx['user']);

    $data = $this->getJson('/api/reports/comps?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data');
    expect($data['headline']['comp_count'])->toBe(0);
});

// =================== LOSS/WASTE VOIDS SECTIONS ===================

it('breaks voided orders down by reason and staff in the loss/waste report', function (): void {
    $ctx = makeMerchantActor();
    $staff = \App\Models\PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Omar']);

    DB::table('pos_orders')->insert([
        [
            'uuid' => (string) Str::uuid(),
            'company_id' => $ctx['company']->id,
            'branch_id' => $ctx['branch']->id,
            'staff_id' => $staff->id,
            'order_type' => 'quick',
            'status' => 'void',
            'void_reason_label' => 'Quality Issue',
            'source' => 'main_pos',
            'grand_total' => '4.500',
            'opened_at' => '2026-06-05 12:00:00',
            'closed_at' => '2026-06-05 12:30:00',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'uuid' => (string) Str::uuid(),
            'company_id' => $ctx['company']->id,
            'branch_id' => $ctx['branch']->id,
            'staff_id' => $staff->id,
            'order_type' => 'quick',
            'status' => 'void',
            'void_reason_label' => null, // legacy void without a code
            'source' => 'main_pos',
            'grand_total' => '2.000',
            'opened_at' => '2026-06-06 12:00:00',
            'closed_at' => '2026-06-06 12:30:00',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $data = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data');

    $byReason = collect($data['voids_by_reason']);
    expect($byReason->firstWhere('reason', 'Quality Issue')['order_value'])->toBe('4.500');
    expect($byReason->firstWhere('reason', 'No reason')['void_count'])->toBe(1);

    expect(collect($data['voids_by_staff'])->firstWhere('staff_name', 'Omar')['void_count'])->toBe(2);
});

// =================== SHIFT REPORT ===================

it('lists shifts with cash variance and a short-exposure summary', function (): void {
    $ctx = makeMerchantActor();
    $staff = \App\Models\PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Aisha']);

    Shift::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'staff_id' => $staff->id,
        'status' => ShiftStatus::Closed->value,
        'opened_at' => '2026-06-05 08:00:00',
        'closed_at' => '2026-06-05 16:00:00',
        'opening_cash' => '20.000',
        'expected_cash' => '120.000',
        'closing_cash' => '118.500',
        'variance' => '-1.500',
    ]);
    Shift::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'staff_id' => $staff->id,
        'status' => ShiftStatus::Open->value,
        'opened_at' => '2026-06-06 08:00:00',
        'opening_cash' => '20.000',
    ]);

    $data = $this->getJson('/api/reports/shifts?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data');

    expect($data['summary']['shift_count'])->toBe(2);
    expect($data['summary']['closed_count'])->toBe(1);
    expect($data['summary']['total_short'])->toBe('-1.500');

    $closed = collect($data['shifts'])->firstWhere('status', 'closed');
    expect($closed['staff_name'])->toBe('Aisha');
    expect($closed['counted_cash'])->toBe('118.500');
    expect($closed['variance'])->toBe('-1.500');
    // Cash collected = expected − opening float.
    expect($closed['cash_collected'])->toBe('100.000');

    $open = collect($data['shifts'])->firstWhere('status', 'open');
    expect($open['variance'])->toBeNull();
});

// =================== SHIFT RE-OPEN ===================

it('re-opens a same-day closed shift (audited) and refuses older ones', function (): void {
    $ctx = makeMerchantActor();

    $today = Shift::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'status' => ShiftStatus::Closed->value,
        'opened_at' => now()->subHours(8),
        'closed_at' => now()->subMinutes(10),
        'opening_cash' => '20.000',
        'expected_cash' => '50.000',
        'closing_cash' => '49.000',
        'variance' => '-1.000',
    ]);
    $yesterday = Shift::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'status' => ShiftStatus::Closed->value,
        'opened_at' => now()->subDay()->subHours(8),
        'closed_at' => now()->subDay(),
        'opening_cash' => '20.000',
        'expected_cash' => '50.000',
        'closing_cash' => '50.000',
        'variance' => '0.000',
    ]);

    // Same business day → re-opened, closing capture cleared.
    $this->postJson("/api/shifts/{$today->uuid}/reopen")->assertOk();
    $fresh = $today->fresh();
    expect($fresh->status)->toBe(ShiftStatus::Open);
    expect($fresh->closed_at)->toBeNull();
    expect($fresh->variance)->toBeNull();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'shift.reopened',
        'auditable_id' => $today->id,
    ]);

    // Yesterday's close → 422.
    $this->postJson("/api/shifts/{$yesterday->uuid}/reopen")->assertUnprocessable();
    // Re-opening an already-open shift → 422.
    $this->postJson("/api/shifts/{$today->uuid}/reopen")->assertUnprocessable();
});

it('blocks reopen cross-tenant and without orders.cancel', function (): void {
    $ctx = makeMerchantActor();
    $other = makeMerchantActor();
    $foreign = Shift::query()->create([
        'company_id' => $other['company']->id,
        'branch_id' => $other['branch']->id,
        'status' => ShiftStatus::Closed->value,
        'opened_at' => now()->subHours(8),
        'closed_at' => now(),
        'opening_cash' => '0.000',
    ]);

    app(\App\Support\MerchantTenantContext::class)->set($ctx['company']->id);
    $this->actingAs($ctx['user']);
    $this->postJson("/api/shifts/{$foreign->uuid}/reopen")->assertNotFound();

    $viewer = makeMerchantActor(MerchantRole::Viewer->value);
    $shift = Shift::query()->create([
        'company_id' => $viewer['company']->id,
        'branch_id' => $viewer['branch']->id,
        'status' => ShiftStatus::Closed->value,
        'opened_at' => now()->subHours(8),
        'closed_at' => now(),
        'opening_cash' => '0.000',
    ]);
    $this->postJson("/api/shifts/{$shift->uuid}/reopen")->assertForbidden();
});
