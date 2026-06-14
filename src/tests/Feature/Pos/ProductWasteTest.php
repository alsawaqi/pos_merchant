<?php

declare(strict_types=1);

/**
 * Product wastage — record waste of a COOKED or READY/BOUGHT-IN product at a
 * branch (the product-units parallel of ingredient waste). Covers: the shelf
 * decrement + waste movement with reason + frozen cost; cooked cost falls back
 * to the recipe cost; the negative-shelf guard; 'other' requires notes; the
 * stock-mode guard; tenant isolation; NO expense; and the Loss/Waste report
 * surfacing it valued at the frozen cost.
 */

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductStockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Seed a branch shelf balance for a product. */
function seedBranchShelf(int $branchId, int $productId, string $qty): void
{
    DB::table('pos_branch_product')->insert([
        'branch_id' => $branchId, 'product_id' => $productId,
        'is_available' => true, 'stock_qty' => $qty,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Read the shelf through the model so the decimal:3 cast formats consistently. */
function shelfQty(int $branchId, int $productId): ?string
{
    $bp = BranchProduct::query()->where('branch_id', $branchId)->where('product_id', $productId)->first();

    return ($bp === null || $bp->stock_qty === null) ? null : (string) $bp->stock_qty;
}

it('wastes a bought-in product, decrements the shelf, freezes cost, books no expense', function (): void {
    $ctx = makeMerchantActor();
    $cola = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'name' => 'Cola', 'cost_price' => '0.200']);
    seedBranchShelf($ctx['branch']->id, $cola->id, '10.000');

    $this->postJson("/api/products/{$cola->uuid}/stock/waste", [
        'branch_uuid' => $ctx['branch']->uuid, 'quantity' => '3', 'reason' => 'expired',
    ])->assertOk();

    // Shelf 10 -> 7.
    expect(shelfQty($ctx['branch']->id, $cola->id))->toBe('7.000');

    // A signed-negative waste movement with reason + frozen unit_cost.
    $m = ProductStockMovement::query()->where('product_id', $cola->id)->where('movement_type', 'waste')->firstOrFail();
    expect((string) $m->quantity)->toBe('-3.000');
    expect($m->reason)->toBe('expired');
    expect((string) $m->unit_cost)->toBe('0.200');
    expect((int) $m->branch_id)->toBe($ctx['branch']->id);

    // Wastage is loss-tracking, never an expense.
    expect(Expense::query()->count())->toBe(0);
});

it('values a cooked product waste at its recipe cost when cost_price is unset', function (): void {
    $ctx = makeMerchantActor();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'kg', 'default_unit_cost' => '0.040']);
    $chapati = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked', 'name' => 'Chapati', 'cost_price' => null]);
    DB::table('pos_product_recipes')->insert([
        'product_id' => $chapati->id, 'ingredient_id' => $ing->id, 'quantity' => '2.000',
        'unit_at_set' => 'kg', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    seedBranchShelf($ctx['branch']->id, $chapati->id, '10.000');

    $this->postJson("/api/products/{$chapati->uuid}/stock/waste", [
        'branch_uuid' => $ctx['branch']->uuid, 'quantity' => '5', 'reason' => 'spoiled',
    ])->assertOk();

    // Recipe cost = 2.000 * 0.040 = 0.080 per chapati, frozen on the movement.
    $m = ProductStockMovement::query()->where('product_id', $chapati->id)->where('movement_type', 'waste')->firstOrFail();
    expect((string) $m->unit_cost)->toBe('0.080');
});

it('cannot waste more than the branch holds', function (): void {
    $ctx = makeMerchantActor();
    $cola = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'cost_price' => '0.200']);
    seedBranchShelf($ctx['branch']->id, $cola->id, '2.000');

    $this->postJson("/api/products/{$cola->uuid}/stock/waste", [
        'branch_uuid' => $ctx['branch']->uuid, 'quantity' => '5', 'reason' => 'dropped',
    ])->assertStatus(422);

    expect(ProductStockMovement::query()->where('product_id', $cola->id)->where('movement_type', 'waste')->exists())->toBeFalse();
    expect(shelfQty($ctx['branch']->id, $cola->id))->toBe('2.000');
});

it('requires notes when the reason is other', function (): void {
    $ctx = makeMerchantActor();
    $cola = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'cost_price' => '0.200']);
    seedBranchShelf($ctx['branch']->id, $cola->id, '10.000');

    $this->postJson("/api/products/{$cola->uuid}/stock/waste", [
        'branch_uuid' => $ctx['branch']->uuid, 'quantity' => '1', 'reason' => 'other',
    ])->assertStatus(422);

    $this->postJson("/api/products/{$cola->uuid}/stock/waste", [
        'branch_uuid' => $ctx['branch']->uuid, 'quantity' => '1', 'reason' => 'other', 'notes' => 'fell in the sink',
    ])->assertOk();
});

it('refuses a made-to-order or untracked product', function (): void {
    $ctx = makeMerchantActor();
    foreach (['ingredient', 'untracked'] as $mode) {
        $p = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => $mode]);
        seedBranchShelf($ctx['branch']->id, $p->id, '10.000');
        $this->postJson("/api/products/{$p->uuid}/stock/waste", [
            'branch_uuid' => $ctx['branch']->uuid, 'quantity' => '1', 'reason' => 'expired',
        ])->assertStatus(422);
    }
});

it('does not waste another company product', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $otherBranch = Branch::factory()->for($other, 'company')->create();
    $foreign = Product::factory()->for($other, 'company')->create(['stock_mode' => 'unit']);
    seedBranchShelf($otherBranch->id, $foreign->id, '10.000');

    $this->postJson("/api/products/{$foreign->uuid}/stock/waste", [
        'branch_uuid' => $otherBranch->uuid, 'quantity' => '1', 'reason' => 'expired',
    ])->assertStatus(404);
});

it('surfaces product waste in the Loss/Waste report valued at the frozen cost, by reason', function (): void {
    $ctx = makeMerchantActor();
    $cola = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'name' => 'Cola', 'cost_price' => '0.200']);
    seedBranchShelf($ctx['branch']->id, $cola->id, '10.000');

    $this->postJson("/api/products/{$cola->uuid}/stock/waste", [
        'branch_uuid' => $ctx['branch']->uuid, 'quantity' => '4', 'reason' => 'expired', 'occurred_at' => '2026-06-15',
    ])->assertOk();

    $data = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data');

    $row = collect($data['product_dispositions'])->firstWhere('product_name', 'Cola');
    expect($row['movement_type'])->toBe('waste');
    expect($row['reason'])->toBe('expired');
    expect($row['total_qty'])->toBe('4.000');
    // 4 * 0.200 frozen = 0.800 loss.
    expect($row['value'])->toBe('0.800');
});
