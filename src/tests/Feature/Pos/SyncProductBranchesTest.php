<?php

declare(strict_types=1);

/*
 * PUT /api/products/{uuid}/branches. LAUNCH-P4 H6 + H7 changed the payload to
 * { branch_scope: 'all'|'selected', branch_ids } — the branch rule only. The
 * endpoint no longer writes shelf counts and no longer deletes branch rows
 * (the older tests here asserted both).
 */

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sells a product at the selected branches only, without any shelf count', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit']);
    $b1 = $ctx['branch'];
    Branch::factory()->for($ctx['company'], 'company')->create();

    $res = $this->putJson("/api/products/{$product->uuid}/branches", [
        'branch_scope' => 'selected',
        'branch_ids' => [$b1->id],
    ])->assertOk();

    expect($product->fresh()->branch_scope)->toBe('selected');
    $row1 = BranchProduct::where(['product_id' => $product->id, 'branch_id' => $b1->id])->first();
    expect((bool) $row1->is_available)->toBeTrue()
        ->and($row1->stock_qty)->toBeNull();
    expect(BranchProduct::where('product_id', $product->id)->count())->toBe(1);
    expect($res->json('data.branch_scope'))->toBe('selected');
});

it('switches other branches off on re-sync instead of deleting their rows', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit']);
    $b1 = $ctx['branch'];
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();
    BranchProduct::query()->create(['branch_id' => $b1->id, 'product_id' => $product->id, 'is_available' => true, 'stock_qty' => 10]);

    $this->putJson("/api/products/{$product->uuid}/branches", [
        'branch_scope' => 'selected',
        'branch_ids' => [$b2->id],
    ])->assertOk();

    $row1 = BranchProduct::where(['product_id' => $product->id, 'branch_id' => $b1->id])->first();
    expect((bool) $row1->is_available)->toBeFalse()
        ->and((float) $row1->stock_qty)->toBe(10.0);
    expect((bool) BranchProduct::where(['product_id' => $product->id, 'branch_id' => $b2->id])->value('is_available'))->toBeTrue();
});

it('rejects a branch that belongs to another company', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $foreignBranch = Branch::factory()->create(); // different company

    $this->putJson("/api/products/{$product->uuid}/branches", [
        'branch_scope' => 'selected',
        'branch_ids' => [$foreignBranch->id],
    ])->assertStatus(422);

    expect(BranchProduct::where('product_id', $product->id)->count())->toBe(0);
});

it('404s when syncing branches on another company product', function (): void {
    makeMerchantActor();
    $foreignProduct = Product::factory()->create(); // different company

    $this->putJson("/api/products/{$foreignProduct->uuid}/branches", [
        'branch_scope' => 'all',
        'branch_ids' => [],
    ])->assertNotFound();
});

it('never writes a shelf count, for any product type', function (): void {
    $ctx = makeMerchantActor();
    $cooked = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked']);
    $b1 = $ctx['branch'];
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();

    // The kitchen produced 12 pieces at b1 (pos_api writes this row).
    BranchProduct::query()->create([
        'branch_id' => $b1->id, 'product_id' => $cooked->id,
        'is_available' => true, 'stock_qty' => 12,
    ]);

    // An old-style payload with counts: the counts are ignored.
    $this->putJson("/api/products/{$cooked->uuid}/branches", [
        'branch_scope' => 'selected',
        'branch_ids' => [$b1->id, $b2->id],
        'branches' => [['branch_id' => $b1->id, 'is_available' => true, 'stock_qty' => 3]],
    ])->assertOk();

    expect((float) BranchProduct::where(['product_id' => $cooked->id, 'branch_id' => $b1->id])->value('stock_qty'))->toBe(12.0);
    expect(BranchProduct::where(['product_id' => $cooked->id, 'branch_id' => $b2->id])->value('stock_qty'))->toBeNull();
});
