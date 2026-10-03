<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B7 — H6 + H7 on the product form.
 *
 * H6: where a product is sold is the product's branch rule (branch_scope
 * 'all' | 'selected'), never its stock rows: receiving stock at one branch
 * must not hide the product elsewhere nor start selling it at a branch that
 * was not selected.
 * H7: saving the product never writes branch shelf counts (the old form re-sent
 * the counts it had loaded, overwrote live ones with no movement, and refused
 * to save when a count was below zero) and never deletes a branch's stock row.
 */

use App\Actions\Pos\Inventory\WriteProductStockMovementAction;
use App\Enums\MerchantRole;
use App\Enums\ProductStockMovementType;
use App\Models\BranchProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function p4Shelf(int $branchId, int $productId, ?string $qty, bool $available = true): void
{
    DB::table('pos_branch_product')->insert([
        'branch_id' => $branchId, 'product_id' => $productId, 'is_available' => $available,
        'stock_qty' => $qty, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('saves "only selected branches" without touching any shelf count, even one below zero', function (): void {
    $ctx = makeMerchantActor();
    $a = $ctx['branch'];
    $b = p4Branch($ctx['company'], 'Seeb');
    $product = p4Product($ctx['company'], 'Water', '0.200', ['stock_mode' => 'unit']);
    p4Shelf($a->id, $product->id, '-3.000');
    p4Shelf($b->id, $product->id, '5.000');

    $this->putJson("/api/products/{$product->uuid}/branches", ['branch_scope' => 'selected', 'branch_ids' => [$a->id]])
        ->assertOk();

    expect(DB::table('pos_products')->where('id', $product->id)->value('branch_scope'))->toBe('selected');
    $rowA = BranchProduct::query()->where(['product_id' => $product->id, 'branch_id' => $a->id])->sole();
    $rowB = BranchProduct::query()->where(['product_id' => $product->id, 'branch_id' => $b->id])->sole();
    expect($rowA->is_available)->toBeTrue()->and((string) $rowA->stock_qty)->toBe('-3.000');
    // Not deleted: the shelf count of the other branch survives.
    expect($rowB->is_available)->toBeFalse()->and((string) $rowB->stock_qty)->toBe('5.000');

    // Back to every branch: rows come back on, counts still untouched.
    $this->putJson("/api/products/{$product->uuid}/branches", ['branch_scope' => 'all', 'branch_ids' => []])->assertOk();
    expect(DB::table('pos_products')->where('id', $product->id)->value('branch_scope'))->toBe('all');
    expect(BranchProduct::query()->where('product_id', $product->id)->where('is_available', false)->count())->toBe(0);
    expect((string) BranchProduct::query()->where(['product_id' => $product->id, 'branch_id' => $b->id])->value('stock_qty'))->toBe('5.000');
});

it('ignores shelf counts sent with the branch rule', function (): void {
    $ctx = makeMerchantActor();
    $product = p4Product($ctx['company'], 'Juice', '0.500', ['stock_mode' => 'unit']);
    p4Shelf($ctx['branch']->id, $product->id, '7.000');

    $this->putJson("/api/products/{$product->uuid}/branches", [
        'branch_scope' => 'selected',
        'branch_ids' => [$ctx['branch']->id],
        'branches' => [['branch_id' => $ctx['branch']->id, 'is_available' => true, 'stock_qty' => 99]],
    ])->assertOk();

    expect((string) BranchProduct::query()->where('product_id', $product->id)->value('stock_qty'))->toBe('7.000');
});

it('needs at least one branch for "only selected branches"', function (): void {
    $ctx = makeMerchantActor();
    $product = p4Product($ctx['company'], 'Tea', '0.300');

    $this->putJson("/api/products/{$product->uuid}/branches", ['branch_scope' => 'selected', 'branch_ids' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_ids']);
    $this->putJson("/api/products/{$product->uuid}/branches", ['branch_scope' => 'nearby', 'branch_ids' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_scope']);
});

it('creates a product sold at selected branches from the wizard, with no shelf count', function (): void {
    $ctx = makeMerchantActor();
    p4Branch($ctx['company'], 'Seeb');

    $this->postJson('/api/products/wizard', [
        'product' => ['name' => 'Karak', 'base_price' => '0.300', 'stock_mode' => 'unit'],
        'addon_group_uuids' => [], 'owned_groups' => [], 'recipe_lines' => [], 'component_lines' => [],
        'branches' => ['branch_scope' => 'selected', 'branch_ids' => [$ctx['branch']->id]],
        'delivery_prices' => [],
    ])->assertCreated()->assertJsonPath('data.branch_scope', 'selected');

    $id = DB::table('pos_products')->where('name', 'Karak')->value('id');
    $rows = BranchProduct::query()->where('product_id', $id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->is_available)->toBeTrue()
        ->and($rows->first()->stock_qty)->toBeNull();
});

it('never lets receiving stock decide where a product is sold', function (): void {
    $ctx = makeMerchantActor();
    $a = $ctx['branch'];
    $b = p4Branch($ctx['company'], 'Seeb');
    $move = app(WriteProductStockMovementAction::class);

    // Every branch: the first stock at A creates an "available" row, which
    // under 'all' restricts nothing.
    $everywhere = p4Product($ctx['company'], 'Cake', '1.000', ['stock_mode' => 'unit']);
    $move->handle($everywhere, $a, ProductStockMovementType::AllocationIn, '4');
    expect(BranchProduct::query()->where(['product_id' => $everywhere->id, 'branch_id' => $a->id])->value('is_available'))->toBeTrue();

    // Only A: stock arriving at B must not start selling it at B.
    $onlyA = p4Product($ctx['company'], 'Cookie', '0.400', ['stock_mode' => 'unit', 'branch_scope' => 'selected']);
    p4Shelf($a->id, $onlyA->id, null);
    $move->handle($onlyA, $b, ProductStockMovementType::AllocationIn, '6');
    $rowB = BranchProduct::query()->where(['product_id' => $onlyA->id, 'branch_id' => $b->id])->sole();
    expect($rowB->is_available)->toBeFalse()->and((string) $rowB->stock_qty)->toBe('6.000');
});

it('keeps the branch rule an all-branches (HQ) decision', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $product = p4Product($ctx['company'], 'Latte', '1.200');

    $this->putJson("/api/products/{$product->uuid}/branches", ['branch_scope' => 'selected', 'branch_ids' => [$ctx['branch']->id]])
        ->assertForbidden();
    expect(DB::table('pos_products')->where('id', $product->id)->value('branch_scope'))->toBe('all');
});
