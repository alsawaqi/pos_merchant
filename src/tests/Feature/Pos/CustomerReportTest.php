<?php

declare(strict_types=1);

/**
 * Phase 7b-2 — Customer Report coverage (blueprint §5.11.8).
 *
 * Covers:
 *   - Top customers by spend (ranked + tenant-scoped)
 *   - Cohort: new vs returning split using min(opened_at)
 *   - Loyalty: points issued + redeemed scoped to window;
 *     outstanding liability snapshot (not window-scoped)
 *   - Tenant isolation + window filter
 *   - Permission gate
 */

use App\Enums\PointLedgerEntryType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPointLedgerEntry;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== TOP CUSTOMERS ===================

it('ranks top customers by total spend in the window', function (): void {
    $ctx = makeMerchantActor();
    $alice = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Alice']);
    $bob = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Bob']);

    // Alice: two orders, 30.000 total. Bob: one order, 50.000.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $alice->id,
        'subtotal' => '10.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $alice->id,
        'subtotal' => '20.000', 'grand_total' => '20.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $bob->id,
        'subtotal' => '50.000', 'grand_total' => '50.000',
        'opened_at' => '2026-06-20 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/customers?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $top = $response->json('data.top_customers');
    expect($top)->toHaveCount(2);
    // Bob ranks first (50 > 30).
    expect($top[0]['customer_name'])->toBe('Bob');
    expect($top[0]['total_spend'])->toBe('50.000');
    expect($top[0]['order_count'])->toBe(1);
    expect($top[1]['customer_name'])->toBe('Alice');
    expect($top[1]['total_spend'])->toBe('30.000');
    expect($top[1]['order_count'])->toBe(2);
});

// =================== COHORT ===================

it('splits the cohort into new vs returning based on min(opened_at)', function (): void {
    $ctx = makeMerchantActor();
    $newCust = Customer::factory()->for($ctx['company'], 'company')->create();
    $returning = Customer::factory()->for($ctx['company'], 'company')->create();

    // Returning customer's FIRST order is BEFORE the window.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $returning->id,
        'subtotal' => '5.000', 'grand_total' => '5.000',
        'opened_at' => '2026-05-15 12:00:00',
    ]);
    // Returning customer ALSO buys in the window.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $returning->id,
        'subtotal' => '8.000', 'grand_total' => '8.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    // New customer's FIRST order falls in the window.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $newCust->id,
        'subtotal' => '12.000', 'grand_total' => '12.000',
        'opened_at' => '2026-06-20 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/customers?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.cohort.new_count'))->toBe(1);
    expect($response->json('data.cohort.returning_count'))->toBe(1);
    expect($response->json('data.cohort.total_count'))->toBe(2);
});

// =================== LOYALTY ===================

it('aggregates loyalty points issued / redeemed in the window + outstanding liability', function (): void {
    $ctx = makeMerchantActor();
    $c = Customer::factory()->for($ctx['company'], 'company')->create(['points_balance' => 120]);

    // In-window: +50 earn.
    CustomerPointLedgerEntry::factory()->for($c, 'customer')->for($ctx['company'], 'company')->create([
        'entry_type' => PointLedgerEntryType::Earn->value,
        'points_delta' => 50,
        'balance_after' => 50,
        'occurred_at' => '2026-06-10 12:00:00',
    ]);
    // In-window: -30 redeem.
    CustomerPointLedgerEntry::factory()->for($c, 'customer')->for($ctx['company'], 'company')->create([
        'entry_type' => PointLedgerEntryType::Redeem->value,
        'points_delta' => -30,
        'balance_after' => 20,
        'occurred_at' => '2026-06-12 14:00:00',
    ]);
    // Out-of-window earn.
    CustomerPointLedgerEntry::factory()->for($c, 'customer')->for($ctx['company'], 'company')->create([
        'entry_type' => PointLedgerEntryType::Earn->value,
        'points_delta' => 100,
        'balance_after' => 120,
        'occurred_at' => '2026-05-01 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/customers?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.loyalty.points_issued'))->toBe(50);
    expect($response->json('data.loyalty.points_redeemed'))->toBe(30);
    expect($response->json('data.loyalty.net_change'))->toBe(20);
    // Liability is a snapshot of customer.points_balance.
    expect($response->json('data.loyalty.outstanding_liability'))->toBe(120);
});

// =================== TENANT ISOLATION ===================

it('does not leak customer data from another company', function (): void {
    $ctx = makeMerchantActor();
    $mine = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Mine']);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'customer_id' => $mine->id,
        'subtotal' => '5.000', 'grand_total' => '5.000',
        'opened_at' => '2026-06-10 12:00:00',
    ]);

    $other = Company::factory()->create();
    $otherBranch = Branch::factory()->for($other, 'company')->create();
    $foreign = Customer::factory()->for($other, 'company')->create();
    Order::factory()->for($other, 'company')->for($otherBranch, 'branch')->paid()->create([
        'customer_id' => $foreign->id,
        'subtotal' => '999.000', 'grand_total' => '999.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/customers?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.top_customers'))->toHaveCount(1);
    expect($response->json('data.top_customers.0.customer_name'))->toBe('Mine');
});

it('returns 403 when actor lacks reports.view', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson('/api/reports/customers?date_from=2026-06-01&date_to=2026-06-30')
        ->assertForbidden();
});
