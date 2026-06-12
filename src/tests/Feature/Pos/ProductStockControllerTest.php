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
