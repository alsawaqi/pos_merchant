<?php

declare(strict_types=1);

/*
 * LAUNCH review add-on — fix order B-2 (the orchestrator's browser check):
 * the owner's broken bottle inside a crate (an inner count that may be
 * lowered, never raised) on purchases, transfers, counts and waste; and the
 * warehouse dialog's Allocate / Transfer by container with a total that may
 * be lowered.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

if (! function_exists('rvB2Milk')) {
    /** Milk with "bottle 1 l" and "crate (12 × bottle 1 l)"; returns [ingredient, bottle id, crate id]. */
    function rvB2Milk(\App\Models\Company $company, array $extra = []): array
    {
        $milk = rvIngredient($company, 'Milk', 'ml', '0', $extra);
        $bottle = rvContainer($milk, 'bottle', '1000');
        $crate = rvContainer($milk, 'crate', '12000', $bottle, '12');

        return [$milk, $bottle, $crate];
    }
}

it('B-2 a purchase of 2 crates may say 23 bottles came (one broken): 23 l, 23 bottles, never 25', function (): void {
    $ctx = makeMerchantActor();
    [$milk, $bottle, $crate] = rvB2Milk($ctx['company']);

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '2', 'leaf_pieces' => '25', 'line_cost' => '4.6',
    ]]])->assertStatus(422);

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '2', 'leaf_pieces' => '23', 'line_cost' => '4.6',
    ]]])->assertCreated();
    expect((float) DB::table('pos_ingredient_stock')->where('ingredient_id', $milk->id)->value('quantity'))->toBe(23000.0)
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 23.0])
        ->and((float) DB::table('pos_ingredients')->where('id', $milk->id)->value('default_unit_cost'))->toBe(0.0002);

    // The amount still follows down from 23 l (a part-used bottle), never above it.
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '1', 'leaf_pieces' => '11', 'amount' => '11.5', 'amount_unit' => 'l', 'line_cost' => '1',
    ]]])->assertStatus(422);
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '1', 'leaf_pieces' => '11', 'amount' => '10.5', 'amount_unit' => 'l', 'line_cost' => '1',
    ]]])->assertCreated();
    expect(rvBreakdownOf($milk, null))->toBe([$bottle => 34.0]);
});

it('B-2 a split purchase with a broken bottle gives each branch whole crates while they last', function (): void {
    $ctx = makeMerchantActor();
    [$milk, $bottle, $crate] = rvB2Milk($ctx['company']);
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '2', 'leaf_pieces' => '23', 'line_cost' => '4.6',
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'pieces' => '1']],
    ]]])->assertCreated();
    expect(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 12.0])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 11.0])
        ->and((float) DB::table('pos_branch_stock')->where('ingredient_id', $milk->id)->value('quantity'))->toBe(12000.0)
        ->and((float) DB::table('pos_ingredient_stock')->where('ingredient_id', $milk->id)->value('quantity'))->toBe(11000.0);
});

it('B-2 transfers, counts and waste take the inner count of a nested container too', function (): void {
    $ctx = makeMerchantActor();
    $b2 = rvBranch($ctx['company']);
    [$milk, $bottle, $crate] = rvB2Milk($ctx['company']);
    rvStock($ctx['branch'], $milk, '24000');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/transfers", ['to_branch_uuid' => $b2->uuid, 'lines' => [[
        'ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($crate), 'pieces' => '1', 'leaf_pieces' => '11']],
    ]]])->assertCreated();
    expect((float) DB::table('pos_branch_stock')->where('branch_id', $b2->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(11000.0)
        ->and(rvBreakdownOf($milk, $b2))->toBe([$bottle => 11.0]);

    $this->postJson("/api/branches/{$b2->uuid}/stock-counts", ['lines' => [[
        'ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($crate), 'pieces' => '1', 'leaf_pieces' => '10']],
    ]]])->assertCreated();
    expect(rvBreakdownOf($milk, $b2))->toBe([$bottle => 10.0]);

    $this->postJson("/api/branches/{$b2->uuid}/waste", ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '1', 'leaf_pieces' => '2', 'reason' => 'broken'])->assertSuccessful();
    expect(rvBreakdownOf($milk, $b2))->toBe([$bottle => 8.0])
        ->and((float) DB::table('pos_branch_stock')->where('branch_id', $b2->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(8000.0);

    // Never raised, and whole for a whole-only item.
    $this->postJson("/api/branches/{$b2->uuid}/waste", ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '1', 'leaf_pieces' => '13', 'reason' => 'broken'])->assertStatus(422);
    [$cream, , $creamCrate] = rvB2Milk($ctx['company'], ['name' => 'Cream', 'allow_fractional_pieces' => false]);
    rvStock($b2, $cream, '12000');
    $this->postJson("/api/branches/{$b2->uuid}/waste", ['ingredient_uuid' => $cream->uuid, 'container_uuid' => rvContainerUuid($creamCrate), 'pieces' => '1', 'leaf_pieces' => '2.5', 'reason' => 'broken'])->assertStatus(422);
});

it('B-2 the warehouse Allocate takes containers per branch and a total that may be lowered (3 bottles = 2.5 l)', function (): void {
    $ctx = makeMerchantActor();
    $b2 = rvBranch($ctx['company']);
    [$milk, $bottle, $crate] = rvB2Milk($ctx['company']);
    rvStock(null, $milk, '20000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '20');

    $this->postJson("/api/ingredients/{$milk->uuid}/stock/allocate", ['allocations' => [
        ['branch_uuid' => $ctx['branch']->uuid, 'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '3']], 'quantity' => '2.5', 'amount_unit' => 'l'],
        ['branch_uuid' => $b2->uuid, 'quantity' => '2', 'unit' => null],
    ], 'unit' => 'l'])->assertOk();

    expect((float) DB::table('pos_branch_stock')->where('branch_id', $ctx['branch']->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(2500.0)
        ->and((float) DB::table('pos_branch_stock')->where('branch_id', $b2->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(2000.0)
        ->and(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 3.0])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 17.0]);

    // Never raised above what the containers hold.
    $this->postJson("/api/ingredients/{$milk->uuid}/stock/allocate", ['allocations' => [
        ['branch_uuid' => $ctx['branch']->uuid, 'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '1']], 'quantity' => '1.5', 'amount_unit' => 'l'],
    ]])->assertStatus(422);
});

it('B-2 the warehouse dialog Transfer takes containers and a total that may be lowered', function (): void {
    $ctx = makeMerchantActor();
    $b2 = rvBranch($ctx['company']);
    [$milk, $bottle] = rvB2Milk($ctx['company']);
    rvStock($ctx['branch'], $milk, '5000');
    rvBreakdown($ctx['company'], $ctx['branch'], $milk, $bottle, '5');

    $this->postJson("/api/ingredients/{$milk->uuid}/stock/transfer", [
        'from_branch_uuid' => $ctx['branch']->uuid, 'to_branch_uuid' => $b2->uuid,
        'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '3']], 'quantity' => '2.5', 'amount_unit' => 'l',
    ])->assertOk();
    expect((float) DB::table('pos_branch_stock')->where('branch_id', $b2->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(2500.0)
        ->and(rvBreakdownOf($milk, $b2))->toBe([$bottle => 3.0])
        ->and(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 2.0]);
});
