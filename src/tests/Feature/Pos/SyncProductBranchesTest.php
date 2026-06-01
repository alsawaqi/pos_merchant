<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns a product to branches with per-branch availability + stock', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $b1 = $ctx['branch'];
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();

    $res = $this->putJson("/api/products/{$product->uuid}/branches", [
        'branches' => [
            ['branch_id' => $b1->id, 'is_available' => true, 'stock_qty' => 20],
            ['branch_id' => $b2->id, 'is_available' => false, 'stock_qty' => null],
        ],
    ])->assertOk();

    expect(BranchProduct::where('product_id', $product->id)->count())->toBe(2);

    $row1 = BranchProduct::where(['product_id' => $product->id, 'branch_id' => $b1->id])->first();
    expect((bool) $row1->is_available)->toBeTrue();
    expect((float) $row1->stock_qty)->toBe(20.0);

    $row2 = BranchProduct::where(['product_id' => $product->id, 'branch_id' => $b2->id])->first();
    expect((bool) $row2->is_available)->toBeFalse();
    expect($row2->stock_qty)->toBeNull();

    expect($res->json('data.branches'))->toHaveCount(2);
});

it('replaces the set on re-sync, removing branches no longer present', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $b1 = $ctx['branch'];
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/branches", [
        'branches' => [
            ['branch_id' => $b1->id, 'is_available' => true, 'stock_qty' => 10],
            ['branch_id' => $b2->id, 'is_available' => true, 'stock_qty' => 5],
        ],
    ])->assertOk();

    $this->putJson("/api/products/{$product->uuid}/branches", [
        'branches' => [
            ['branch_id' => $b2->id, 'is_available' => true, 'stock_qty' => 99],
        ],
    ])->assertOk();

    expect(BranchProduct::where('product_id', $product->id)->count())->toBe(1);
    expect(BranchProduct::where(['product_id' => $product->id, 'branch_id' => $b1->id])->exists())->toBeFalse();
    expect((float) BranchProduct::where(['product_id' => $product->id, 'branch_id' => $b2->id])->value('stock_qty'))->toBe(99.0);
});

it('rejects a branch that belongs to another company', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $foreignBranch = Branch::factory()->create(); // different company

    $this->putJson("/api/products/{$product->uuid}/branches", [
        'branches' => [
            ['branch_id' => $foreignBranch->id, 'is_available' => true, 'stock_qty' => 1],
        ],
    ])->assertStatus(422);

    expect(BranchProduct::where('product_id', $product->id)->count())->toBe(0);
});

it('404s when syncing branches on another company product', function (): void {
    makeMerchantActor();
    $foreignProduct = Product::factory()->create(); // different company

    $this->putJson("/api/products/{$foreignProduct->uuid}/branches", [
        'branches' => [],
    ])->assertNotFound();
});
