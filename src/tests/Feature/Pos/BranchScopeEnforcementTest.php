<?php

declare(strict_types=1);

/**
 * P-G5 — branch-scope enforcement matrix (spec feature 5: role = WHAT,
 * branch scope = WHERE; "endpoint-by-endpoint tests so nothing is
 * missed").
 *
 * The actor everywhere is a MANAGER (broad permissions) restricted to
 * branch A, with branch B in the same company outside the scope —
 * every 403 below is a SCOPE refusal, never a role refusal. Covers:
 *
 *   - the EnsureBranchScope route middleware (branch / floor /
 *     posStaff / transfer / restockRequest params) + the
 *     cross-tenant-404-before-scope-403 ordering
 *   - both branch list endpoints + the auth payload
 *   - the ReportFilter clamp (default → scope; explicit → 403;
 *     empty scope → 403; exports inherit)
 *   - orders list/detail, dashboard, productions, expenses,
 *     transfers, restock requests, staff, customer 360
 *   - central-warehouse mutations = unrestricted accounts only
 *   - the portal-users grant meta-rule and the catalogue
 *     branch-assignment HQ-only rule
 *   - SuperAdmin ALWAYS bypasses any stored scope
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Floor;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Order;
use App\Models\PosStaff;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\RestockRequest;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A Manager restricted to the default branch (A); branchB exists in the
 * same company but OUTSIDE the scope.
 *
 * @return array{company: Company, branch: Branch, branchB: Branch, user: \App\Models\User}
 */
function scopedManagerCtx(): array
{
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['branchB'] = Branch::factory()->for($ctx['company'], 'company')->create();
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();

    return $ctx;
}

function scopedPaidOrder(array $ctx, Branch $branch, string $gross): Order
{
    // gross_sales on the sales report = SUM(subtotal); the dashboard
    // sums grand_total — keep them equal so both assert cleanly.
    return Order::factory()->for($ctx['company'], 'company')->for($branch, 'branch')->paid()->create([
        'subtotal' => $gross,
        'grand_total' => $gross,
        'opened_at' => now(),
    ]);
}

// =================== ROUTE MIDDLEWARE ===================

it('allows in-scope branch routes and 403s out-of-scope ones', function (): void {
    $ctx = scopedManagerCtx();

    $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock")->assertOk();
    $this->getJson("/api/branches/{$ctx['branchB']->uuid}/stock")->assertForbidden();
    $this->getJson("/api/pos/branches/{$ctx['branchB']->uuid}")->assertForbidden();
    $this->getJson("/api/pos/branches/{$ctx['branchB']->uuid}/devices")->assertForbidden();
    $this->getJson("/api/pos/branches/{$ctx['branchB']->uuid}/activity")->assertForbidden();
});

it('keeps a cross-tenant branch a 404, never a scope 403', function (): void {
    scopedManagerCtx();
    $foreign = Branch::factory()->for(Company::factory()->create(), 'company')->create();

    // Scope must not reveal that the foreign uuid exists.
    $this->getJson("/api/branches/{$foreign->uuid}/stock")->assertNotFound();
});

it('derives the scope from flat floor routes', function (): void {
    $ctx = scopedManagerCtx();
    $floorB = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create();

    $this->patchJson("/api/floors/{$floorB->uuid}", ['name' => 'Renamed'])->assertForbidden();
    $this->getJson("/api/branches/{$ctx['branch']->uuid}/floors")->assertOk();
});

it('blocks pos-staff row actions outside the scope', function (): void {
    $ctx = scopedManagerCtx();
    $staffB = PosStaff::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create();

    $this->postJson("/api/pos-staff/{$staffB->getRouteKey()}/suspend")->assertForbidden();
});

it('blocks restock-request rows outside the scope', function (): void {
    $ctx = scopedManagerCtx();
    $requestB = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create();

    $this->getJson("/api/restock-requests/{$requestB->uuid}")->assertForbidden();
});

// =================== LISTS + AUTH PAYLOAD ===================

it('filters both branch lists to the scope', function (): void {
    $ctx = scopedManagerCtx();

    $lean = $this->getJson('/api/branches')->assertOk()->json('data');
    expect($lean)->toHaveCount(1);
    expect($lean[0]['uuid'])->toBe($ctx['branch']->uuid);

    $full = $this->getJson('/api/pos/branches')->assertOk()->json('data');
    expect($full)->toHaveCount(1);
});

it('exposes branch_scope on the auth payload and nulls it for superadmins', function (): void {
    $ctx = scopedManagerCtx();
    expect($this->getJson('/auth/user')->assertOk()->json('user.branch_scope'))
        ->toBe([$ctx['branch']->id]);

    // A SuperAdmin's stored scope is dead weight — the payload says null.
    $sa = makeMerchantActor();
    $sa['user']->forceFill(['branch_scope_json' => [$sa['branch']->id]])->save();
    expect($this->getJson('/auth/user')->assertOk()->json('user.branch_scope'))->toBeNull();
});

// =================== REPORTFILTER CLAMP ===================

it('clamps reports to the scope by default', function (): void {
    $ctx = scopedManagerCtx();
    scopedPaidOrder($ctx, $ctx['branch'], '10.000');
    scopedPaidOrder($ctx, $ctx['branchB'], '25.000');

    $today = now()->toDateString();
    $res = $this->getJson("/api/reports/sales?date_from={$today}&date_to={$today}")->assertOk();
    expect($res->json('data.headline.gross_sales'))->toBe('10.000');
});

it('rejects an explicit out-of-scope report filter, exports included', function (): void {
    $ctx = scopedManagerCtx();
    $today = now()->toDateString();
    $b = $ctx['branchB']->id;

    $this->getJson("/api/reports/sales?date_from={$today}&date_to={$today}&branch_ids[]={$b}")
        ->assertForbidden();
    $this->getJson("/api/reports/sales/export?format=csv&date_from={$today}&date_to={$today}&branch_ids[]={$b}")
        ->assertForbidden();
});

it('locks an empty scope out of branch data entirely', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => []])->save();

    $today = now()->toDateString();
    $this->getJson("/api/reports/sales?date_from={$today}&date_to={$today}")->assertForbidden();
});

it('scopes the orders list filter and the order detail', function (): void {
    $ctx = scopedManagerCtx();
    $orderA = scopedPaidOrder($ctx, $ctx['branch'], '10.000');
    $orderB = scopedPaidOrder($ctx, $ctx['branchB'], '25.000');

    $today = now()->toDateString();
    $this->getJson("/api/orders?date_from={$today}&date_to={$today}&branch_ids[]={$ctx['branchB']->id}")
        ->assertForbidden();
    $this->getJson("/api/orders/{$orderA->uuid}")->assertOk();
    $this->getJson("/api/orders/{$orderB->uuid}")->assertForbidden();
});

it('lets a superadmin ignore any stored scope', function (): void {
    $sa = makeMerchantActor();
    $branchB = Branch::factory()->for($sa['company'], 'company')->create();
    $sa['user']->forceFill(['branch_scope_json' => [$sa['branch']->id]])->save();
    $orderB = Order::factory()->for($sa['company'], 'company')->for($branchB, 'branch')->paid()->create([
        'grand_total' => '25.000',
        'opened_at' => now(),
    ]);

    $this->getJson("/api/orders/{$orderB->uuid}")->assertOk();
});

// =================== DASHBOARD ===================

it('scopes the dashboard widgets', function (): void {
    $ctx = scopedManagerCtx();
    scopedPaidOrder($ctx, $ctx['branch'], '10.000');
    scopedPaidOrder($ctx, $ctx['branchB'], '25.000');

    $data = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');
    expect($data['mtd']['order_count'])->toBe(1);
    expect($data['mtd']['gross'])->toBe('10.000');
    expect($data['top_branches'])->toHaveCount(1);
    expect($data['top_branches'][0]['branch_name'])->toBe($ctx['branch']->name);
});

// =================== PRODUCTIONS / EXPENSES ===================

it('rejects an explicit out-of-scope production filter', function (): void {
    $ctx = scopedManagerCtx();

    $this->getJson("/api/productions?branch_uuid={$ctx['branchB']->uuid}")->assertForbidden();
    $this->getJson('/api/productions')->assertOk();
});

it('scopes the expense queue and expense writes', function (): void {
    $ctx = scopedManagerCtx();
    Expense::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    Expense::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create();
    // A company-wide (HQ) expense — invisible to scoped users.
    Expense::factory()->for($ctx['company'], 'company')->create(['branch_id' => null]);

    expect($this->getJson('/api/expenses')->assertOk()->json('data'))->toHaveCount(1);
    $this->getJson("/api/expenses?branch_id={$ctx['branchB']->id}")->assertForbidden();

    $this->postJson('/api/expenses', [
        'branch_id' => $ctx['branchB']->id,
        'category' => 'supplies',
        'amount' => '5.000',
    ])->assertForbidden();
    // Company-wide write = an HQ act.
    $this->postJson('/api/expenses', [
        'category' => 'supplies',
        'amount' => '5.000',
    ])->assertForbidden();
    // In scope still works.
    $this->postJson('/api/expenses', [
        'branch_id' => $ctx['branch']->id,
        'category' => 'supplies',
        'amount' => '5.000',
    ])->assertCreated();
});

// =================== TRANSFERS / RESTOCK INBOX ===================

it('scopes the transfers list and blocks out-of-scope endpoints', function (): void {
    $ctx = scopedManagerCtx();
    $branchC = Branch::factory()->for($ctx['company'], 'company')->create();

    // A↔B touches the scope; B↔C does not.
    $touching = BranchTransfer::create([
        'company_id' => $ctx['company']->id,
        'from_branch_id' => $ctx['branch']->id,
        'to_branch_id' => $ctx['branchB']->id,
        'transferred_by_user_id' => $ctx['user']->id,
        'transferred_at' => now(),
    ]);
    $foreignPair = BranchTransfer::create([
        'company_id' => $ctx['company']->id,
        'from_branch_id' => $ctx['branchB']->id,
        'to_branch_id' => $branchC->id,
        'transferred_by_user_id' => $ctx['user']->id,
        'transferred_at' => now(),
    ]);

    $rows = $this->getJson('/api/branch-transfers')->assertOk()->json('data');
    expect(collect($rows)->pluck('uuid'))->toContain($touching->uuid)
        ->not->toContain($foreignPair->uuid);

    $this->getJson("/api/branch-transfers?branch={$ctx['branchB']->uuid}")->assertForbidden();
    $this->getJson("/api/branch-transfers/{$foreignPair->uuid}")->assertForbidden();
    $this->getJson("/api/branch-transfers/{$touching->uuid}")->assertOk();

    // Creating a transfer with an out-of-scope DESTINATION is refused.
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/transfers", [
        'to_branch_uuid' => $ctx['branchB']->uuid,
        'lines' => [['ingredient_uuid' => $sugar->uuid, 'quantity' => '1']],
    ])->assertForbidden();
});

it('scopes the restock-request inbox', function (): void {
    $ctx = scopedManagerCtx();
    RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create();

    expect($this->getJson('/api/restock-requests')->assertOk()->json('data'))->toHaveCount(1);
    $this->getJson("/api/restock-requests?branch={$ctx['branchB']->uuid}")->assertForbidden();
});

// =================== CENTRAL WAREHOUSE ===================

it('locks central-warehouse mutations to unrestricted accounts', function (): void {
    $ctx = scopedManagerCtx();
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive", ['quantity' => '5'])
        ->assertForbidden();
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/receive-distribute", [
        'quantity' => '5',
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '5']],
    ])->assertForbidden();
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '1']],
    ])->assertForbidden();
    // Central adjust = HQ; a branch adjust within scope works.
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/adjust", [
        'signed_quantity' => '-1',
        'note' => 'Spill',
    ])->assertForbidden();
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/adjust", [
        'branch_uuid' => $ctx['branch']->uuid,
        'signed_quantity' => '2',
        'note' => 'Recount',
    ])->assertOk();
    // Transfers need BOTH sides in scope.
    $this->postJson("/api/ingredients/{$sugar->uuid}/stock/transfer", [
        'from_branch_uuid' => $ctx['branch']->uuid,
        'to_branch_uuid' => $ctx['branchB']->uuid,
        'quantity' => '1',
    ])->assertForbidden();
});

it('hides central ledger rows and foreign branches from a scoped warehouse view', function (): void {
    $ctx = scopedManagerCtx();
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Seed a central receive directly (an HQ act that already happened).
    IngredientStock::create([
        'company_id' => $ctx['company']->id,
        'ingredient_id' => $sugar->id,
        'quantity' => '100.000',
    ]);
    StockMovement::create([
        'branch_id' => null,
        'ingredient_id' => $sugar->id,
        'movement_type' => 'received',
        'quantity' => '100.000',
        'occurred_at' => now(),
    ]);

    $data = $this->getJson("/api/ingredients/{$sugar->uuid}/stock")->assertOk()->json('data');
    expect($data['branches'])->toHaveCount(1);
    expect($data['recent_movements'])->toHaveCount(0);
});

it('locks the product central pool too', function (): void {
    $ctx = scopedManagerCtx();
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit']);

    $this->postJson("/api/products/{$cake->uuid}/stock/receive", ['quantity' => '5'])
        ->assertForbidden();
});

// =================== STAFF ===================

it('scopes the staff roster and hiring', function (): void {
    $ctx = scopedManagerCtx();
    PosStaff::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $staffB = PosStaff::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create();

    expect($this->getJson('/api/pos-staff')->assertOk()->json('data'))->toHaveCount(1);

    $this->postJson('/api/pos-staff', [
        'name' => 'New Hire',
        'branch_id' => $ctx['branchB']->id,
        'position' => 'cashier',
    ])->assertForbidden();

    // Reassigning own-branch staff OUT of scope is refused too — but the
    // out-of-scope row itself is already blocked by the middleware.
    $this->patchJson("/api/pos-staff/{$staffB->getRouteKey()}", ['name' => 'X'])->assertForbidden();
});

// =================== CUSTOMER 360 ===================

it('filters the customer 360 to the scope', function (): void {
    $ctx = scopedManagerCtx();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()
        ->create(['customer_id' => $customer->id, 'grand_total' => '10.000', 'opened_at' => now()]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->paid()
        ->create(['customer_id' => $customer->id, 'grand_total' => '25.000', 'opened_at' => now()]);

    $orders = $this->getJson("/api/customers/{$customer->uuid}/orders")->assertOk()->json('data');
    expect($orders['rows'])->toHaveCount(1);

    $analytics = $this->getJson("/api/customers/{$customer->uuid}/analytics")->assertOk()->json('data');
    expect($analytics['rollups']['total_spend'])->toBe('10.000');
});

// =================== GRANTS + CATALOGUE ===================

it('limits portal-user scope grants to the actor own scope', function (): void {
    $ctx = scopedManagerCtx();

    $payload = static fn (string $email, ?array $scope): array => [
        'name' => 'Teammate',
        'email' => $email,
        'role' => MerchantRole::Viewer->value,
        'branch_scope' => $scope,
    ];

    // Out-of-scope grant + the "all branches" grant are both refused.
    $this->postJson('/api/portal-users', $payload('t1@example.com', [$ctx['branchB']->id]))
        ->assertForbidden();
    $this->postJson('/api/portal-users', $payload('t2@example.com', null))
        ->assertForbidden();
    // A subset of the actor's own scope is fine.
    $this->postJson('/api/portal-users', $payload('t3@example.com', [$ctx['branch']->id]))
        ->assertCreated();
});

it('keeps catalogue branch assignment HQ-only', function (): void {
    $ctx = scopedManagerCtx();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/branches", ['branches' => []])
        ->assertForbidden();
    $this->patchJson("/api/categories/{$category->uuid}", ['branch_ids' => [$ctx['branch']->id]])
        ->assertForbidden();
    // Edits that do NOT touch branch availability still work.
    $this->patchJson("/api/categories/{$category->uuid}", ['name' => 'Renamed'])->assertOk();
});

it('hides out-of-scope branch inventory from the catalogue product list', function (): void {
    $ctx = scopedManagerCtx();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit']);
    // Stock the product at BOTH branches; the scoped user must see only A.
    DB::table('pos_branch_product')->insert([
        ['branch_id' => $ctx['branch']->id, 'product_id' => $product->id, 'is_available' => true, 'stock_qty' => '7.000'],
        ['branch_id' => $ctx['branchB']->id, 'product_id' => $product->id, 'is_available' => true, 'stock_qty' => '99.000'],
    ]);

    $row = collect($this->getJson('/api/products')->assertOk()->json('data'))
        ->firstWhere('uuid', $product->uuid);
    $branchIds = collect($row['branches'])->pluck('branch_id');
    expect($branchIds)->toContain($ctx['branch']->id)->not->toContain($ctx['branchB']->id);
});

it('refuses a scoped admin minting an all-branches user by omitting branch_scope', function (): void {
    $ctx = scopedManagerCtx();

    // No branch_scope key at all → would default to NULL (all branches).
    $this->postJson('/api/portal-users', [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'role' => MerchantRole::Viewer->value,
    ])->assertForbidden();
});

it('enforces scope on the table, shift and bound-expense route params', function (): void {
    $ctx = scopedManagerCtx();
    $floorB = Floor::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create();
    $tableB = \App\Models\Table::factory()->for($ctx['company'], 'company')->for($floorB, 'floor')->create();
    $shiftB = \App\Models\Shift::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create([
        'status' => 'closed',
        'closed_at' => now(),
    ]);
    $expenseB = Expense::factory()->for($ctx['company'], 'company')->for($ctx['branchB'], 'branch')->create();

    $this->patchJson("/api/tables/{$tableB->uuid}", ['label' => 'X'])->assertForbidden();
    $this->postJson("/api/shifts/{$shiftB->uuid}/reopen")->assertForbidden();
    $this->postJson("/api/expenses/{$expenseB->uuid}/review")->assertForbidden();
});

it('scopes the dedicated stock movements ledgers', function (): void {
    $ctx = scopedManagerCtx();
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create();
    // One branch-A row, one foreign-branch row, one central (NULL) row.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($sugar, 'ingredient')->create(['movement_type' => 'restock', 'quantity' => '5.000']);
    StockMovement::factory()->for($ctx['branchB'], 'branch')->for($sugar, 'ingredient')->create(['movement_type' => 'restock', 'quantity' => '9.000']);
    StockMovement::create(['branch_id' => null, 'ingredient_id' => $sugar->id, 'movement_type' => 'received', 'quantity' => '100.000', 'occurred_at' => now()]);

    $rows = $this->getJson("/api/ingredients/{$sugar->uuid}/stock/movements")->assertOk()->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['branch_id'])->toBe($ctx['branch']->id);
});
