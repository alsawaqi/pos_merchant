<?php

declare(strict_types=1);

/**
 * Phase 7b-1 — Sales Report coverage.
 *
 * The blueprint Phase 7 exit checklist says:
 *
 *   "Seeded test data of 1000 orders produces correct,
 *    reconciled totals on every report."
 *
 * This file validates the Sales Report against the Phase 7a
 * SeedDemoOrdersAction at smaller volume (50-100 orders) so
 * the suite stays fast. The math is the same regardless of
 * scale.
 *
 * Covers:
 *   - Permission gate (Viewer + Manager allowed, etc.)
 *   - Headline metric math: gross / net / tax / refunds /
 *     order_count
 *   - Breakdowns: by_hour, by_weekday, by_payment_method,
 *     by_order_type, by_branch
 *   - Date window filter
 *   - branch_ids filter
 *   - Validation: required date fields + 422
 *   - Tenant isolation: foreign company's orders don't leak
 */

use App\Actions\Pos\Orders\SeedDemoOrdersAction;
use App\Actions\Pos\Reports\SalesReportAction;
use App\Data\Reports\ReportFilter;
use App\Enums\MerchantRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// =================== PERMISSION GATING ===================

it('returns 403 when actor lacks reports.view', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    // CashierSupervisor doesn't get reports.view per the 7b-1
    // role matrix update? Let me check — yes, supervisors DO
    // get reports.view ("see today's sales numbers"). So a
    // role that DOES NOT get it: use a custom dropdown of the
    // viewer or test the absence indirectly.
    // For this test, use a role-less user by directly creating
    // a portal user without assigning any role.
    $u = $ctx['user'];
    $u->syncRoles([]); // strip the role
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')
        ->assertForbidden();
});

it('returns 200 when actor has reports.view (Manager)', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);

    $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();
});

// =================== VALIDATION ===================

it('returns 422 when date_from or date_to is missing', function (): void {
    makeMerchantActor();

    $this->getJson('/api/reports/sales?date_to=2026-06-30')
        ->assertStatus(422)->assertJsonValidationErrors(['date_from']);

    $this->getJson('/api/reports/sales?date_from=2026-06-01')
        ->assertStatus(422)->assertJsonValidationErrors(['date_to']);
});

it('returns 422 when date_to is before date_from', function (): void {
    makeMerchantActor();

    $this->getJson('/api/reports/sales?date_from=2026-06-30&date_to=2026-06-01')
        ->assertStatus(422)->assertJsonValidationErrors(['date_to']);
});

// =================== EMPTY DATA ===================

it('returns zeros when no orders exist in the window', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.gross_sales'))->toBe('0.000');
    expect($response->json('data.headline.order_count'))->toBe(0);
    expect($response->json('data.by_hour'))->toBe([]);
    expect($response->json('data.by_hour_weekday'))->toBe([]);
    expect($response->json('data.by_branch'))->toBe([]);
});

// =================== HEADLINE MATH ===================

it('reconciles headline totals against a hand-built set of paid orders', function (): void {
    $ctx = makeMerchantActor();
    // Three paid orders, known totals.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000',
        'discount_total' => '1.000',
        'tax_total' => '0.500',
        'grand_total' => '9.500',
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '5.000',
        'discount_total' => '0.000',
        'tax_total' => '0.250',
        'grand_total' => '5.250',
        'opened_at' => '2026-06-15 14:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '20.000',
        'discount_total' => '2.000',
        'tax_total' => '1.000',
        'grand_total' => '19.000',
        'opened_at' => '2026-06-20 19:00:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.headline.gross_sales'))->toBe('35.000');
    expect($response->json('data.headline.discount_total'))->toBe('3.000');
    expect($response->json('data.headline.net_sales'))->toBe('32.000');
    expect($response->json('data.headline.tax_total'))->toBe('1.750');
    expect($response->json('data.headline.order_count'))->toBe(3);
});

it('includes refunded orders in refunds_total but not gross_sales', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000',
        'grand_total' => '10.000',
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->refunded()->create([
        'subtotal' => '5.000',
        'grand_total' => '5.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.headline.gross_sales'))->toBe('10.000');
    expect($response->json('data.headline.refunds_total'))->toBe('5.000');
    expect($response->json('data.headline.refund_count'))->toBe(1);
});

// =================== DATE WINDOW ===================

it('filters orders by the date window (opened_at) inclusive', function (): void {
    $ctx = makeMerchantActor();
    // In-window
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    // Out-of-window (before)
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '99.000', 'grand_total' => '99.000',
        'opened_at' => '2026-05-15 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.headline.gross_sales'))->toBe('10.000');
});

// =================== BRANCH FILTER ===================

it('filters by branch_ids when provided', function (): void {
    $ctx = makeMerchantActor();
    $branch2 = Branch::factory()->for($ctx['company'], 'company')->create();

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($branch2, 'branch')->paid()->create([
        'subtotal' => '20.000', 'grand_total' => '20.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    // No filter: both branches.
    $all = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($all->json('data.headline.gross_sales'))->toBe('30.000');

    // Filter to ctx branch only.
    $filtered = $this->getJson(
        '/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30&branch_ids[]='.$ctx['branch']->id,
    )->assertOk();
    expect($filtered->json('data.headline.gross_sales'))->toBe('10.000');
});

// =================== TENANT ISOLATION ===================

it('does not leak orders from another company', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $other = Company::factory()->create();
    $otherBranch = Branch::factory()->for($other, 'company')->create();
    Order::factory()->for($other, 'company')->for($otherBranch, 'branch')->paid()->create([
        'subtotal' => '999.000', 'grand_total' => '999.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.headline.gross_sales'))->toBe('10.000');
});

// =================== BREAKDOWNS ===================

it('breaks down sales by_hour with a detectable peak', function (): void {
    $ctx = makeMerchantActor();
    // Three orders at hour 12, one at hour 19.
    foreach ([1, 2, 3] as $_) {
        Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
            'subtotal' => '5.000', 'grand_total' => '5.000',
            'opened_at' => '2026-06-15 12:30:00',
        ]);
    }
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '3.000', 'grand_total' => '3.000',
        'opened_at' => '2026-06-15 19:30:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $byHour = collect($response->json('data.by_hour'));
    $hour12 = $byHour->firstWhere('hour', 12);
    $hour19 = $byHour->firstWhere('hour', 19);
    expect($hour12['count'])->toBe(3);
    expect($hour12['gross'])->toBe('15.000');
    expect($hour19['count'])->toBe(1);
});

it('breaks down sales by_hour_weekday into a (weekday, hour) matrix', function (): void {
    $ctx = makeMerchantActor();
    // 2026-06-15 is a Monday (weekday 1), 12:30 — two orders.
    foreach ([1, 2] as $_) {
        Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
            'subtotal' => '5.000', 'grand_total' => '5.000',
            'opened_at' => '2026-06-15 12:30:00',
        ]);
    }
    // 2026-06-16 is a Tuesday (weekday 2), 19:00 — one order.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '3.000', 'grand_total' => '3.000',
        'opened_at' => '2026-06-16 19:00:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $cells = collect($response->json('data.by_hour_weekday'));
    $mon12 = $cells->first(fn ($c) => $c['weekday'] === 1 && $c['hour'] === 12);
    $tue19 = $cells->first(fn ($c) => $c['weekday'] === 2 && $c['hour'] === 19);
    expect($mon12['gross'])->toBe('10.000');
    expect($mon12['count'])->toBe(2);
    expect($tue19['gross'])->toBe('3.000');
    expect($tue19['count'])->toBe(1);
    // Sparse: only buckets with orders are returned (2 here).
    expect($cells)->toHaveCount(2);
});

it('breaks down sales by_order_type', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
        'order_type' => 'quick',
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->dineIn()->create([
        'subtotal' => '20.000', 'grand_total' => '20.000',
        'opened_at' => '2026-06-15 13:00:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $byType = collect($response->json('data.by_order_type'));
    expect($byType->firstWhere('type', 'quick')['gross'])->toBe('10.000');
    expect($byType->firstWhere('type', 'dine_in')['gross'])->toBe('20.000');
});

it('breaks down sales by_payment_method using only successful payments', function (): void {
    $ctx = makeMerchantActor();
    $cashOrder = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    Payment::factory()->for($cashOrder, 'order')->create(['amount' => '10.000']); // cash default

    $cardOrder = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '25.000', 'grand_total' => '25.000',
        'opened_at' => '2026-06-15 13:00:00',
    ]);
    Payment::factory()->for($cardOrder, 'order')->card()->create(['amount' => '25.000']);

    // A failed card payment should NOT count.
    Payment::factory()->for($cardOrder, 'order')->failed()->create(['amount' => '99.000']);

    // P-F5 — bank_pos (the bank's own standalone terminal) groups as its
    // own method bucket.
    $bankPosOrder = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '7.000', 'grand_total' => '7.000',
        'opened_at' => '2026-06-15 14:00:00',
    ]);
    Payment::factory()->for($bankPosOrder, 'order')->create(['method' => PaymentMethod::BankPos->value, 'amount' => '7.000']);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $byMethod = collect($response->json('data.by_payment_method'));
    $cash = $byMethod->firstWhere('method', 'cash');
    $card = $byMethod->firstWhere('method', 'card');
    $bankPos = $byMethod->firstWhere('method', 'bank_pos');
    expect($cash['amount'])->toBe('10.000');
    expect($cash['count'])->toBe(1);
    expect($card['amount'])->toBe('25.000');
    expect($card['count'])->toBe(1);
    expect($bankPos['amount'])->toBe('7.000');
    expect($bankPos['count'])->toBe(1);
});

// =================== SEEDER RECONCILIATION ===================

it('SeedDemoOrdersAction output reconciles: report gross_sales == SUM(grand_total) for paid orders', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->count(3)->create(['base_price' => '2.500']);

    $seeder = app(SeedDemoOrdersAction::class);
    $endingAt = Carbon::create(2026, 6, 30, 23, 59, 59);
    $seeder->handle($ctx['company'], 50, 4242, $endingAt);

    // Independent calculation against the DB.
    $expectedSum = (float) DB::table('pos_orders')
        ->where('company_id', $ctx['company']->id)
        ->where('status', OrderStatus::Paid->value)
        ->sum('grand_total');

    $response = $this->getJson(
        '/api/reports/sales?date_from=2026-05-01&date_to=2026-07-01',
    )->assertOk();

    expect((float) $response->json('data.headline.gross_sales'))
        ->toBe($expectedSum);
    expect($response->json('data.headline.order_count'))->toBe(50);
});

// =================== DIRECT ACTION CALL ===================

it('SalesReportAction works directly when called by Phase 8 internal code paths', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $filter = ReportFilter::fromArray([
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        'consolidated' => true,
    ]);
    $report = app(SalesReportAction::class)->handle($filter);

    expect($report['headline']['gross_sales'])->toBe('10.000');
    expect($report['window']['consolidated'])->toBeTrue();
});

// =================== COGS / GROSS PROFIT ===================

it('computes COGS and gross_profit from order-item recipe snapshots', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'discount_total' => '0.000', 'tax_total' => '0.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    // 2 units; recipe cost per unit = 0.25 × 0.400 = 0.100 OMR → COGS = 0.200.
    OrderItem::factory()->for($order, 'order')->for($product, 'product')->create([
        'qty' => '2.000', 'unit_price_snapshot' => '5.000', 'line_total' => '10.000',
        'recipe_snapshot_json' => [['ingredient_id' => 1, 'qty' => 0.25, 'unit' => 'l', 'unit_cost' => 0.400]],
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.headline.cogs'))->toBe('0.200');
    expect($response->json('data.headline.gross_profit'))->toBe('9.800'); // net_sales 10.000 − cogs 0.200
});

it('PD5 cash model: net_profit = net_sales minus ALL expenses (ingredients included)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'discount_total' => '0.000', 'tax_total' => '0.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    OrderItem::factory()->for($order, 'order')->for($product, 'product')->create([
        'qty' => '2.000', 'unit_price_snapshot' => '5.000', 'line_total' => '10.000',
        'recipe_snapshot_json' => [['ingredient_id' => 1, 'qty' => 0.25, 'unit' => 'l', 'unit_cost' => 0.400]], // COGS 0.200 (informational)
    ]);
    // PD5 cash model — EVERY purchase counts as an expense when bought, so the
    // ingredient expense now counts too (the merchant's chosen accounting).
    \App\Models\Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'category' => \App\Enums\ExpenseCategory::Utilities->value, 'amount' => '4.000', 'logged_at' => '2026-06-15 09:00:00',
    ]);
    \App\Models\Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create([
        'category' => \App\Enums\ExpenseCategory::Ingredients->value, 'amount' => '5.000', 'logged_at' => '2026-06-15 09:00:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    // gross_profit + cogs stay as an informational recipe margin...
    expect($response->json('data.headline.gross_profit'))->toBe('9.800');
    expect($response->json('data.headline.cogs'))->toBe('0.200');
    // ...but net profit is the cash one: net_sales − ALL expenses (no COGS
    // double-count). 10.000 − (4.000 + 5.000) = 1.000.
    expect($response->json('data.headline.operating_expenses'))->toBe('9.000');
    expect($response->json('data.headline.net_profit'))->toBe('1.000');

    // The by-category breakdown drives the new expenses view.
    $byCat = collect($response->json('data.by_expense_category'))->keyBy('category');
    expect((string) $byCat['ingredients']['amount'])->toBe('5.000')
        ->and((string) $byCat['utilities']['amount'])->toBe('4.000');
});

it('excludes rejected expenses from net_profit', function (): void {
    $ctx = makeMerchantActor();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'discount_total' => '0.000', 'tax_total' => '0.000', 'grand_total' => '10.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);
    \App\Models\Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->rejected()->create([
        'category' => \App\Enums\ExpenseCategory::Utilities->value, 'amount' => '99.000', 'logged_at' => '2026-06-15 09:00:00',
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.headline.operating_expenses'))->toBe('0.000');
    expect($response->json('data.headline.net_profit'))->toBe('10.000'); // gross_profit 10.000 - 0
});

it('adds add-on ingredient cost to COGS', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'grand_total' => '10.000', 'opened_at' => '2026-06-15 12:00:00',
    ]);
    $item = OrderItem::factory()->for($order, 'order')->for($product, 'product')->create([
        'qty' => '2.000', 'line_total' => '10.000',
        'recipe_snapshot_json' => [['ingredient_id' => 1, 'qty' => 0.25, 'unit' => 'l', 'unit_cost' => 0.400]], // 0.200
    ]);
    // Add-on ingredient: 1 × 0.200 OMR × 2 units = 0.400.
    OrderItemAddon::factory()->for($item, 'orderItem')->create([
        'ingredient_snapshot_json' => ['ingredient_id' => 2, 'qty' => 1, 'unit' => 'shot', 'unit_cost' => 0.200],
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($response->json('data.headline.cogs'))->toBe('0.600'); // 0.200 recipe + 0.400 add-on
});
