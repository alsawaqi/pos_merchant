<?php

declare(strict_types=1);

/**
 * Phase 7b-2 — Discount Report coverage (blueprint §5.11.7).
 *
 * Covers:
 *   - Headline: total_discount, gross_sales, discount_pct_of_gross,
 *     order_count, discounted_order_count
 *   - by_branch breakdown
 *   - by_staff breakdown (order.staff_id)
 *   - by_rule breakdown (pos_order_discounts, Phase 8.10)
 *   - Tenant isolation + window filter
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Discount;
use App\Models\Order;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// =================== HEADLINE ===================

it('aggregates total_discount + gross_sales + discount_pct_of_gross', function (): void {
    $ctx = makeMerchantActor();
    // 100 gross, 20 discount -> 20% of gross.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '100.000',
        'discount_total' => '20.000',
        'grand_total' => '80.000',
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '50.000',
        'discount_total' => '0.000',
        'grand_total' => '50.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/discounts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.headline.total_discount'))->toBe('20.000');
    expect($response->json('data.headline.gross_sales'))->toBe('150.000');
    // 20 / 150 = 13.33...% (rounded to 2 dp).
    expect((float) $response->json('data.headline.discount_pct_of_gross'))->toBe(13.33);
    expect($response->json('data.headline.order_count'))->toBe(2);
    expect($response->json('data.headline.discounted_order_count'))->toBe(1);
});

it('returns 0% discount_pct when gross_sales is zero (no division-by-zero)', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/reports/discounts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect((float) $response->json('data.headline.discount_pct_of_gross'))->toBe(0.0);
});

// =================== BY BRANCH ===================

it('breaks down by_branch with discount_pct per branch', function (): void {
    $ctx = makeMerchantActor();
    $branch2 = Branch::factory()->for($ctx['company'], 'company')->create();

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '100.000', 'discount_total' => '10.000', 'grand_total' => '90.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($branch2, 'branch')->paid()->create([
        'subtotal' => '40.000', 'discount_total' => '0.000', 'grand_total' => '40.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/discounts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $byBranch = collect($response->json('data.by_branch'));
    $b1 = $byBranch->firstWhere('branch_id', $ctx['branch']->id);
    $b2 = $byBranch->firstWhere('branch_id', $branch2->id);
    expect($b1['total_discount'])->toBe('10.000');
    expect((float) $b1['discount_pct'])->toBe(10.0);
    expect($b2['total_discount'])->toBe('0.000');
    expect((float) $b2['discount_pct'])->toBe(0.0);
});

// =================== BY STAFF ===================

it('breaks down discount by_staff (order.staff_id)', function (): void {
    $ctx = makeMerchantActor();
    $sara = PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Sara']);

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '100.000', 'discount_total' => '10.000', 'grand_total' => '90.000',
        'staff_id' => $sara->id, 'opened_at' => '2026-06-15 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '50.000', 'discount_total' => '0.000', 'grand_total' => '50.000',
        'staff_id' => $sara->id, 'opened_at' => '2026-06-16 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/discounts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $row = collect($response->json('data.by_staff'))->firstWhere('staff_id', $sara->id);
    expect($row['staff_name'])->toBe('Sara');
    expect($row['total_discount'])->toBe('10.000');
    expect($row['discounted_order_count'])->toBe(1);
});

// =================== BY RULE ===================

it('breaks down discount by_rule from the discount-application records', function (): void {
    $ctx = makeMerchantActor();
    $rule = Discount::factory()->for($ctx['company'], 'company')->create(['name' => 'Happy Hour']);

    $o1 = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '50.000', 'discount_total' => '7.000', 'grand_total' => '43.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    $o2 = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '30.000', 'discount_total' => '3.000', 'grand_total' => '27.000',
        'opened_at' => '2026-06-16 12:00:00',
    ]);

    // The discount-application records pos_api writes at order.create.
    DB::table('pos_order_discounts')->insert([
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o1->id, 'order_item_id' => null, 'discount_id' => $rule->id, 'name_snapshot' => 'Happy Hour', 'amount_type_snapshot' => 'percent', 'amount' => '5.000', 'applied_at' => '2026-06-15 12:00:00', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o2->id, 'order_item_id' => null, 'discount_id' => $rule->id, 'name_snapshot' => 'Happy Hour', 'amount_type_snapshot' => 'percent', 'amount' => '3.000', 'applied_at' => '2026-06-16 12:00:00', 'created_at' => now(), 'updated_at' => now()],
        // Manual / ad-hoc discount on o1 — no rule behind it.
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o1->id, 'order_item_id' => null, 'discount_id' => null, 'name_snapshot' => 'Manager comp', 'amount_type_snapshot' => null, 'amount' => '2.000', 'applied_at' => '2026-06-15 12:00:00', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson('/api/reports/discounts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $byRule = collect($response->json('data.by_rule'));

    $happy = $byRule->firstWhere('discount_id', $rule->id);
    expect($happy['rule_name'])->toBe('Happy Hour');
    expect($happy['total_discount'])->toBe('8.000');       // 5 + 3 across two orders
    expect($happy['order_count'])->toBe(2);
    expect($happy['application_count'])->toBe(2);

    $manual = $byRule->firstWhere('rule_name', 'Manager comp');
    expect($manual['discount_id'])->toBeNull();
    expect($manual['total_discount'])->toBe('2.000');
    expect($manual['order_count'])->toBe(1);
});

it('does not count discount applications from unpaid or out-of-window orders', function (): void {
    $ctx = makeMerchantActor();
    $rule = Discount::factory()->for($ctx['company'], 'company')->create(['name' => 'Promo']);

    // Out of window (May) + an open (unpaid) order — neither should count.
    $stale = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'opened_at' => '2026-05-15 12:00:00',
    ]);
    $open = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'status' => 'open', 'opened_at' => '2026-06-15 12:00:00',
    ]);

    DB::table('pos_order_discounts')->insert([
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $stale->id, 'order_item_id' => null, 'discount_id' => $rule->id, 'name_snapshot' => 'Promo', 'amount_type_snapshot' => 'percent', 'amount' => '9.000', 'applied_at' => '2026-05-15 12:00:00', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $open->id, 'order_item_id' => null, 'discount_id' => $rule->id, 'name_snapshot' => 'Promo', 'amount_type_snapshot' => 'percent', 'amount' => '4.000', 'applied_at' => '2026-06-15 12:00:00', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson('/api/reports/discounts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.by_rule'))->toBe([]);
});

// =================== TENANT ISOLATION ===================

it('does not leak discount data from another company', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'discount_total' => '1.000', 'grand_total' => '9.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $other = Company::factory()->create();
    $otherBranch = Branch::factory()->for($other, 'company')->create();
    Order::factory()->for($other, 'company')->for($otherBranch, 'branch')->paid()->create([
        'subtotal' => '999.000', 'discount_total' => '500.000', 'grand_total' => '499.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/discounts?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.headline.total_discount'))->toBe('1.000');
});
