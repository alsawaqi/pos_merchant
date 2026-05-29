<?php

declare(strict_types=1);

/**
 * Phase 7b-2 — Discount Report coverage (blueprint §5.11.7).
 *
 * Covers:
 *   - Headline: total_discount, gross_sales, discount_pct_of_gross,
 *     order_count, discounted_order_count
 *   - by_branch breakdown
 *   - by_rule / by_staff stubs (Phase 8 lands the data path)
 *   - Tenant isolation + window filter
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

// =================== STUB BREAKDOWNS ===================

it('breaks down discount by_staff and keeps by_rule stubbed', function (): void {
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

    // Per-rule still needs the pos_api discount-application record.
    expect($response->json('data.by_rule'))->toBe([]);
    expect($response->json('data._phase.by_rule_stub'))->toContain('discount-application');
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
