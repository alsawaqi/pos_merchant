<?php

declare(strict_types=1);

/*
 * LAUNCH-P2 P2-4 — one way to bring stock in for the pilot: Goods received.
 * With pos.inventory.single_stock_in on (the default) the branch Restock,
 * the branch Purchase and the central Receive / Receive & distribute are
 * refused with a clear 422; goods received, allocation, transfers,
 * adjustments, waste and counts keep working.
 */

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is on by default and says so to the portal', function (): void {
    makeMerchantActor();

    expect(config('pos.inventory.single_stock_in'))->toBeTrue();
    $this->getJson('/api/inventory/settings')->assertOk()->assertExactJson(['data' => ['single_stock_in' => true]]);

    config(['pos.inventory.single_stock_in' => false]);
    $this->getJson('/api/inventory/settings')->assertOk()->assertJsonPath('data.single_stock_in', false);
});

it('refuses the three other stock-in entry points with a clear 422', function (): void {
    $ctx = makeMerchantActor();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'g']);
    $branch = $ctx['branch']->uuid;

    $refusals = [
        $this->postJson("/api/branches/{$branch}/stock/restock", ['ingredient_uuid' => $flour->uuid, 'quantity' => '100']),
        $this->postJson("/api/branches/{$branch}/stock/purchase", ['ingredient_uuid' => $flour->uuid, 'units' => '100', 'total_paid' => '1.000']),
        $this->postJson("/api/ingredients/{$flour->uuid}/stock/receive", ['quantity' => '100', 'total_cost' => '1.000']),
        $this->postJson("/api/ingredients/{$flour->uuid}/stock/receive-distribute", ['quantity' => '100', 'total_cost' => '1.000', 'allocations' => []]),
    ];
    foreach ($refusals as $response) {
        $response->assertStatus(422)
            ->assertJsonPath('code', 'single_stock_in')
            ->assertJsonPath('message', 'Stock comes in through Goods received only. Record this delivery as a goods-received note (Inventory → Purchase receipts).');
    }
    expect(StockMovement::query()->count())->toBe(0);
});

it('keeps goods received, allocation, transfers, adjustments, waste and counts working', function (): void {
    $ctx = makeMerchantActor();
    $other = Branch::factory()->for($ctx['company'], 'company')->create();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'g', 'default_unit_cost' => '0']);

    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'quantity' => '1000', 'line_cost' => '0.400']],
    ])->assertCreated();
    $this->postJson("/api/ingredients/{$flour->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '600']],
    ])->assertOk();
    $this->postJson("/api/ingredients/{$flour->uuid}/stock/transfer", [
        'from_branch_uuid' => $ctx['branch']->uuid, 'to_branch_uuid' => $other->uuid, 'quantity' => '100',
    ])->assertOk();
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $flour->uuid, 'signed_quantity' => '-10', 'note' => 'spilled',
    ])->assertCreated();
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $flour->uuid, 'quantity' => '5', 'reason' => 'spoiled',
    ])->assertCreated();
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $flour->uuid, 'counted_units' => '480']],
    ])->assertCreated();

    expect((float) BranchStock::query()->where('branch_id', $ctx['branch']->id)->value('quantity'))->toBe(480.0);
});
