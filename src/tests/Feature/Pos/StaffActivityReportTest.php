<?php

declare(strict_types=1);

/**
 * Phase 7b-3 — Staff Activity Report coverage (blueprint §5.11.10).
 */

use App\Models\Order;
use App\Models\PosStaff;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('aggregates per-staff orders_paid + revenue + voids + discounts', function (): void {
    $ctx = makeMerchantActor();
    $staff = PosStaff::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Sam Cashier',
    ]);

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'staff_id' => $staff->id,
        'grand_total' => '10.000',
        'discount_total' => '0.000',
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'staff_id' => $staff->id,
        'grand_total' => '20.000',
        'discount_total' => '5.000',
        'opened_at' => '2026-06-11 13:00:00',
    ]);
    // A voided order
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->voided()->create([
        'staff_id' => $staff->id,
        'grand_total' => '0.000',
        'opened_at' => '2026-06-12 14:00:00',
    ]);

    $response = $this->getJson('/api/reports/staff-activity?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['staff_name'])->toBe('Sam Cashier');
    expect($rows[0]['orders_paid'])->toBe(2);
    expect($rows[0]['revenue'])->toBe('30.000');
    expect($rows[0]['avg_ticket'])->toBe('15.000');
    expect($rows[0]['voids'])->toBe(1);
    expect($rows[0]['discounts_applied'])->toBe(1);
});

it('sums hours_logged from closed shifts in the window', function (): void {
    $ctx = makeMerchantActor();
    $staff = PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Sam']);

    // Order so the staff appears in the result set.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'staff_id' => $staff->id,
        'grand_total' => '5.000',
        'opened_at' => '2026-06-10 12:00:00',
    ]);

    // 8-hour shift (factory default).
    Shift::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->closed()->create([
        'staff_id' => $staff->id,
        'opened_at' => '2026-06-10 08:00:00',
        'closed_at' => '2026-06-10 16:00:00',
    ]);

    $response = $this->getJson('/api/reports/staff-activity?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $rows = $response->json('data.rows');
    // 8 hours total.
    expect((float) $rows[0]['hours_logged'])->toBe(8.0);
});

it('returns empty rows when no staff have orders in the window', function (): void {
    makeMerchantActor();
    $response = $this->getJson('/api/reports/staff-activity?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.rows'))->toBe([]);
});
