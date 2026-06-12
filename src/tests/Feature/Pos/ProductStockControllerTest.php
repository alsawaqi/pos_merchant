<?php

declare(strict_types=1);

/**
 * Phase 7 — central pool + per-branch distribution for UNIT products.
 *
 * Covers: receive → central pool; allocate central → branches (debits the pool,
 * credits each branch's stock_qty, writes paired ledger rows); over-allocation
 * rejected; branch→branch transfer + overdraw rejected; adjust; the unit-mode
 * guard; and cross-tenant isolation.
 */

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function unitProduct(array $ctx): Product
{
    return Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit']);
}

function branchStockQty(int $branchId, int $productId): ?string
{
    // Read through the model so the decimal:3 cast formats consistently.
    $bp = BranchProduct::query()
        ->where('branch_id', $branchId)
        ->where('product_id', $productId)
        ->first();

    return ($bp === null || $bp->stock_qty === null) ? null : (string) $bp->stock_qty;
}

it('receives finished goods into the central pool', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);

    $res = $this->postJson("/api/products/{$product->uuid}/stock/receive", [
        'quantity' => '50',
        'note' => 'Baked 50 cakes',
    ])->assertOk();

    expect($res->json('data.central_quantity'))->toBe('50.000');

    $central = ProductStock::query()->where('product_id', $product->id)->firstOrFail();
    expect((string) $central->quantity)->toBe('50.000');

    $movement = ProductStockMovement::query()->where('product_id', $product->id)->firstOrFail();
    expect($movement->movement_type->value)->toBe('received');
    expect($movement->branch_id)->toBeNull();
    expect((string) $movement->quantity)->toBe('50.000');
});

it('allocates the central pool across branches and debits the pool', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $product = unitProduct($ctx);

    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '50'])->assertOk();

    $res = $this->postJson("/api/products/{$product->uuid}/stock/allocate", [
        'allocations' => [
            ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '20'],
            ['branch_uuid' => $branchB->uuid, 'quantity' => '15'],
        ],
        'note' => 'Morning distribution',
    ])->assertOk();

    // Central 50 - 35 = 15.
    expect($res->json('data.central_quantity'))->toBe('15.000');
    expect(branchStockQty($ctx['branch']->id, $product->id))->toBe('20.000');
    expect(branchStockQty($branchB->id, $product->id))->toBe('15.000');

    // Ledger: received + (allocation_out + allocation_in) x2.
    expect(ProductStockMovement::query()->where('product_id', $product->id)->count())->toBe(5);
});

it('rejects allocating more than the central balance', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);
    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '10'])->assertOk();

    $this->postJson("/api/products/{$product->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '25']],
    ])->assertStatus(422);

    // Nothing moved — the pool is untouched and no branch row was created.
    expect((string) ProductStock::query()->where('product_id', $product->id)->firstOrFail()->quantity)->toBe('10.000');
    expect(BranchProduct::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('receives a bulk quantity and distributes it across branches in one call', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $product = unitProduct($ctx);

    // 80 in: 50 to branch A, 30 to branch B — all in one request.
    $res = $this->postJson("/api/products/{$product->uuid}/stock/receive-distribute", [
        'quantity' => '80',
        'allocations' => [
            ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '50'],
            ['branch_uuid' => $branchB->uuid, 'quantity' => '30'],
        ],
        'note' => 'Bulk bake distribution',
    ])->assertOk();

    // Fully distributed -> central nets to zero.
    expect($res->json('data.central_quantity'))->toBe('0.000');
    expect(branchStockQty($ctx['branch']->id, $product->id))->toBe('50.000');
    expect(branchStockQty($branchB->id, $product->id))->toBe('30.000');

    // Ledger: received + (allocation_out + allocation_in) x2.
    expect(ProductStockMovement::query()->where('product_id', $product->id)->count())->toBe(5);
    expect(ProductStockMovement::query()->where('product_id', $product->id)->where('movement_type', 'received')->count())->toBe(1);
});

it('leaves the undistributed remainder in the central pool', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);

    // 80 in, only 50 sent out -> 30 stays central.
    $res = $this->postJson("/api/products/{$product->uuid}/stock/receive-distribute", [
        'quantity' => '80',
        'allocations' => [
            ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '50'],
        ],
    ])->assertOk();

    expect($res->json('data.central_quantity'))->toBe('30.000');
    expect(branchStockQty($ctx['branch']->id, $product->id))->toBe('50.000');
});

it('rejects distributing more than the received total and writes nothing', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $product = unitProduct($ctx);

    // 50 received but 60 distributed (30 + 30) -> rejected, atomic rollback.
    $this->postJson("/api/products/{$product->uuid}/stock/receive-distribute", [
        'quantity' => '50',
        'allocations' => [
            ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '30'],
            ['branch_uuid' => $branchB->uuid, 'quantity' => '30'],
        ],
    ])->assertStatus(422);

    // Nothing moved: no central row, no branch rows, no ledger.
    expect(ProductStock::query()->where('product_id', $product->id)->exists())->toBeFalse();
    expect(BranchProduct::query()->where('product_id', $product->id)->exists())->toBeFalse();
    expect(ProductStockMovement::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('transfers units between branches', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $product = unitProduct($ctx);
    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '30'])->assertOk();
    $this->postJson("/api/products/{$product->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '30']],
    ])->assertOk();

    $this->postJson("/api/products/{$product->uuid}/stock/transfer", [
        'from_branch_uuid' => $ctx['branch']->uuid,
        'to_branch_uuid' => $branchB->uuid,
        'quantity' => '12',
    ])->assertOk();

    expect(branchStockQty($ctx['branch']->id, $product->id))->toBe('18.000');
    expect(branchStockQty($branchB->id, $product->id))->toBe('12.000');
});

it('rejects a transfer that overdraws the source branch', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $product = unitProduct($ctx);
    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '5'])->assertOk();
    $this->postJson("/api/products/{$product->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '5']],
    ])->assertOk();

    $this->postJson("/api/products/{$product->uuid}/stock/transfer", [
        'from_branch_uuid' => $ctx['branch']->uuid,
        'to_branch_uuid' => $branchB->uuid,
        'quantity' => '9',
    ])->assertStatus(422);

    expect(branchStockQty($ctx['branch']->id, $product->id))->toBe('5.000');
});

it('adjusts a branch count with a required note', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);
    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '10'])->assertOk();
    $this->postJson("/api/products/{$product->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '10']],
    ])->assertOk();

    $this->postJson("/api/products/{$product->uuid}/stock/adjust", [
        'branch_uuid' => $ctx['branch']->uuid,
        'signed_quantity' => '-3',
        'note' => 'Dropped 3 on the floor',
    ])->assertOk();

    expect(branchStockQty($ctx['branch']->id, $product->id))->toBe('7.000');
});

it('rejects an adjustment with no note', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);

    $this->postJson("/api/products/{$product->uuid}/stock/adjust", [
        'signed_quantity' => '5',
    ])->assertStatus(422);
});

it('refuses stock operations on a non-unit product', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '5'])->assertStatus(422);
});

it('allows stock operations on a P-G1 cooked product', function (): void {
    // Cooked products sell from the same branch shelf stock as unit ones
    // (production fills it), so the stock dialog applies to them too.
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked']);

    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '5'])->assertOk();
    expect($this->getJson("/api/products/{$product->uuid}/stock")->assertOk()->json('data.central_quantity'))
        ->toBe('5.000');
});

it('404s on a product owned by another company', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Product::factory()->for($other, 'company')->create(['stock_mode' => 'unit']);

    $this->getJson("/api/products/{$foreign->uuid}/stock")->assertNotFound();
});

// ---- PD2 — bought-in goods are purchases: cost on receive books an expense ----

it('books a stock-purchase expense when a receive carries a cost', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);

    $this->postJson("/api/products/{$product->uuid}/stock/receive", [
        'quantity' => '80',
        'total_cost' => '12.500',
        'note' => 'Friday delivery',
    ])->assertOk();

    $this->assertDatabaseHas('pos_expenses', [
        'company_id' => $ctx['company']->id,
        'branch_id' => null,
        'category' => 'stock_purchases',
        'status' => 'recorded',
    ]);
    $expense = \App\Models\Expense::query()->where('company_id', $ctx['company']->id)->firstOrFail();
    expect((string) $expense->amount)->toBe('12.500')
        ->and($expense->note)->toContain($product->name)
        ->and($expense->note)->toContain('Friday delivery')
        ->and($expense->logged_by_portal_user_id)->toBe($ctx['user']->id);

    // The receive movement points back at the expense row.
    $movement = ProductStockMovement::query()->where('product_id', $product->id)->firstOrFail();
    expect($movement->reference_type)->toBe(\App\Models\Expense::class)
        ->and((int) $movement->reference_id)->toBe($expense->id);

    // The display category is created lazily exactly once, then reused.
    $this->postJson("/api/products/{$product->uuid}/stock/receive", [
        'quantity' => '10',
        'total_cost' => '2.000',
    ])->assertOk();
    expect(\App\Models\ExpenseCategory::query()->where('company_id', $ctx['company']->id)->where('key', 'stock_purchases')->count())->toBe(1)
        ->and(\App\Models\Expense::query()->where('company_id', $ctx['company']->id)->count())->toBe(2);
});

it('books no expense when a receive has no cost (corrections / free goods)', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);

    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '50'])->assertOk();
    $this->postJson("/api/products/{$product->uuid}/stock/receive", ['quantity' => '5', 'total_cost' => '0'])->assertOk();

    expect(\App\Models\Expense::query()->count())->toBe(0)
        ->and(ProductStockMovement::query()->where('product_id', $product->id)->whereNotNull('reference_id')->count())->toBe(0);
});

it('books exactly ONE expense for a costed receive-and-distribute', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/products/{$product->uuid}/stock/receive-distribute", [
        'quantity' => '80',
        'total_cost' => '40.000',
        'allocations' => [
            ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '50'],
            ['branch_uuid' => $b2->uuid, 'quantity' => '30'],
        ],
    ])->assertOk();

    // The split changes nothing about the money: one purchase, one expense.
    expect(\App\Models\Expense::query()->where('category', 'stock_purchases')->count())->toBe(1)
        ->and((string) \App\Models\Expense::query()->firstOrFail()->amount)->toBe('40.000');
});

it('respects a deliberately deleted stock-purchases category (no duplicate key crash)', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);
    $cat = \App\Models\ExpenseCategory::query()->create([
        'company_id' => $ctx['company']->id,
        'name' => 'Stock purchases',
        'name_ar' => 'x',
        'key' => 'stock_purchases',
        'is_active' => true,
        'sort_order' => 6,
    ]);
    $cat->delete();

    // The expense still books under the key; the trashed category row is
    // neither resurrected nor duplicated into the (company, key) unique.
    $this->postJson("/api/products/{$product->uuid}/stock/receive", [
        'quantity' => '10',
        'total_cost' => '5.000',
    ])->assertOk();

    expect(\App\Models\Expense::query()->where('category', 'stock_purchases')->count())->toBe(1)
        ->and(\App\Models\ExpenseCategory::withTrashed()->where('company_id', $ctx['company']->id)->where('key', 'stock_purchases')->count())->toBe(1);
});

it('seeds the FULL default category set when a fresh company books its first costed receive', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);

    // A brand-new company has zero categories until something seeds them —
    // the lazy create must not insert a lone row that would suppress the
    // default seeder's any-row guard forever.
    expect(\App\Models\ExpenseCategory::query()->where('company_id', $ctx['company']->id)->count())->toBe(0);

    $this->postJson("/api/products/{$product->uuid}/stock/receive", [
        'quantity' => '10',
        'total_cost' => '5.000',
    ])->assertOk();

    $keys = \App\Models\ExpenseCategory::query()->where('company_id', $ctx['company']->id)->pluck('key')->all();
    expect($keys)->toHaveCount(7)
        ->and($keys)->toContain('utilities', 'supplies', 'ingredients', 'maintenance', 'salaries', 'other', 'stock_purchases');
});

it('refuses a recipe on a ready / bought-in product but lets it be cleared', function (): void {
    $ctx = makeMerchantActor();
    $product = unitProduct($ctx);
    $ingredient = \App\Models\Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Its cost is booked at receive — a recipe would double-count it.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $ingredient->uuid, 'quantity' => 1]],
    ])->assertUnprocessable();

    // Clearing stays allowed (how a converted product sheds a stale recipe).
    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => []])->assertOk();
});
