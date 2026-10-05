<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 8 — D transfers, counts, waste and restock
 * by container (owner decision D6, inventory audit B3, tester calls 9 and 10).
 *
 *   transfer  containers + an amount that may be lowered (3 bottles = 4 l when
 *             one is half used), never raised; the breakdown MOVES; the
 *             containers are kept on the line;
 *   count     one row per item holding several containers; the total may be
 *             lowered; the breakdown is SET to exactly what was counted;
 *             without containers the legacy rule applies (a / b / c);
 *   waste     by container: the amount defaults to pieces × size, may be
 *             lowered; the breakdown loses those containers (never below 0:
 *             a clamp row records the rest); a count shortfall never takes;
 *   restock   an optional container on a line; the allocation moves that
 *             breakdown warehouse → branch.
 */

use App\Models\StockCountLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('transfers by container, lowered for a half-used bottle, and moves the breakdown', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $to = rvBranch($ctx['company']);
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1500');
    rvStock($from, $milk, '7500');
    rvBreakdown($ctx['company'], $from, $milk, $bottle, '5');

    $res = $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '3']], 'quantity' => '4', 'unit' => 'l']],
    ])->assertCreated();

    expect($res->json('data.lines.0.quantity'))->toBe('4000.000')
        ->and($res->json('data.lines.0.containers.0.pieces'))->toBe('3')
        ->and($res->json('data.lines.0.containers.0.container_label'))->toBe('bottle 1.5 l')
        ->and(rvBreakdownOf($milk, $from))->toBe([$bottle => 2.0])
        ->and(rvBreakdownOf($milk, $to))->toBe([$bottle => 3.0])
        ->and((float) DB::table('pos_branch_stock')->where('branch_id', $to->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(4000.0);

    // Never raised above what the containers hold; and the amount fills in when blank.
    $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '1']], 'quantity' => '1.6', 'unit' => 'l']],
    ])->assertStatus(422);
    $this->postJson("/api/branches/{$from->uuid}/transfers", [
        'to_branch_uuid' => $to->uuid,
        'lines' => [['ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '1']]]],
    ])->assertCreated()->assertJsonPath('data.lines.0.quantity', '1500.000');
});

it('transfers typed in a container as that container (the warehouse dialog shape)', function (): void {
    $ctx = makeMerchantActor();
    $from = $ctx['branch'];
    $to = rvBranch($ctx['company']);
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $crate = rvContainer($milk, 'crate', '12000', $bottle, '12');
    rvStock($from, $milk, '24000');
    rvBreakdown($ctx['company'], $from, $milk, $bottle, '24');

    $this->postJson("/api/ingredients/{$milk->uuid}/stock/transfer", [
        'from_branch_uuid' => $from->uuid, 'to_branch_uuid' => $to->uuid, 'quantity' => '1', 'unit' => rvToken($crate),
    ])->assertOk();

    expect(rvBreakdownOf($milk, $to))->toBe([$bottle => 12.0])
        ->and(rvBreakdownOf($milk, $from))->toBe([$bottle => 12.0]);
});

it('counts by container: the total may be lowered and the breakdown is set exactly', function (): void {
    $ctx = makeMerchantActor();
    $branch = $ctx['branch'];
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $big = rvContainer($milk, 'bottle', '1500');
    $small = rvContainer($milk, 'bottle', '500');
    $jug = rvContainer($milk, 'jug', '2000');
    rvStock($branch, $milk, '9000');
    rvBreakdown($ctx['company'], $branch, $milk, $jug, '2');

    $res = $this->postJson("/api/branches/{$branch->uuid}/stock-counts", ['lines' => [[
        'ingredient_uuid' => $milk->uuid,
        'containers' => [
            ['container_uuid' => rvContainerUuid($big), 'pieces' => '3'],
            ['container_uuid' => rvContainerUuid($small), 'pieces' => '5'],
        ],
        // 4.5 l + 2.5 l = 7 l on the shelf, one bottle part used: 6.8 l.
        'counted_units' => '6.8', 'unit' => 'l',
    ]]])->assertCreated();

    expect($res->json('data.lines.0.counted_units'))->toBe('6800.000')
        ->and(collect($res->json('data.lines.0.containers'))->pluck('pieces')->all())->toBe(['3', '5'])
        ->and(rvBreakdownOf($milk, $branch))->toBe([$big => 3.0, $small => 5.0])
        ->and(DB::table('pos_branch_stock')->where('branch_id', $branch->id)->value('containers_counted_at'))->not->toBeNull();

    // Never raised above what the containers hold.
    $this->postJson("/api/branches/{$branch->uuid}/stock-counts", ['lines' => [[
        'ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($big), 'pieces' => '1']], 'counted_units' => '2', 'unit' => 'l',
    ]]])->assertStatus(422);
});

it('applies the legacy rule to a count with no containers', function (): void {
    $ctx = makeMerchantActor();
    $branch = $ctx['branch'];

    // (a) pieces in the count container when it is the only leaf → set.
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1500');
    $this->patchJson("/api/ingredients/{$milk->uuid}", ['count_container_uuid' => rvContainerUuid($bottle)])->assertOk();
    rvStock($branch, $milk, '3000');
    $this->postJson("/api/branches/{$branch->uuid}/stock-counts", ['lines' => [['ingredient_uuid' => $milk->uuid, 'counted_pieces' => '4']]])->assertCreated();
    expect(rvBreakdownOf($milk, $branch))->toBe([$bottle => 4.0]);

    // (b) a total of 0 → clear.
    $this->postJson("/api/branches/{$branch->uuid}/stock-counts", ['lines' => [['ingredient_uuid' => $milk->uuid, 'counted_units' => '0']]])->assertCreated();
    expect(rvBreakdownOf($milk, $branch))->toBe([]);

    // (c) otherwise → leave it and stamp a total-only count.
    $oil = rvIngredient($ctx['company'], 'Oil', 'ml');
    $can = rvContainer($oil, 'can', '5000');
    rvContainer($oil, 'bottle', '1000');
    rvStock($branch, $oil, '10000');
    rvBreakdown($ctx['company'], $branch, $oil, $can, '2');
    $this->postJson("/api/branches/{$branch->uuid}/stock-counts", ['lines' => [['ingredient_uuid' => $oil->uuid, 'counted_units' => '8', 'unit' => 'l']]])->assertCreated();
    expect(rvBreakdownOf($oil, $branch))->toBe([$can => 2.0])
        ->and(DB::table('pos_branch_stock')->where('branch_id', $branch->id)->where('ingredient_id', $oil->id)->value('containers_total_count_at'))->not->toBeNull();
});

it('wastes by container, takes it from the breakdown, and clamps at 0', function (): void {
    $ctx = makeMerchantActor();
    $branch = $ctx['branch'];
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0.001');
    $bottle = rvContainer($milk, 'bottle', '1500');
    rvStock($branch, $milk, '6000');
    rvBreakdown($ctx['company'], $branch, $milk, $bottle, '1');

    $res = $this->postJson("/api/branches/{$branch->uuid}/waste", [
        'ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '2', 'reason' => 'spoiled',
    ])->assertCreated();

    expect($res->json('data.quantity'))->toBe('3000.000')
        ->and($res->json('data.pieces'))->toBe('2')
        ->and($res->json('data.container_label'))->toBe('bottle 1.5 l')
        ->and(rvBreakdownOf($milk, $branch))->toBe([])
        ->and(DB::table('pos_stock_container_movements')->where('reason', 'waste')->value('delta_pieces'))->toEqual('-1.0000')
        ->and(DB::table('pos_stock_container_movements')->where('reason', 'clamp')->count())->toBe(1);

    // Lowered (half a bottle spilled), never raised.
    $this->postJson("/api/branches/{$branch->uuid}/waste", [
        'ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '1', 'quantity' => '750', 'reason' => 'spoiled',
    ])->assertCreated()->assertJsonPath('data.quantity', '750.000');
    $this->postJson("/api/branches/{$branch->uuid}/waste", [
        'ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '1', 'quantity' => '1600', 'reason' => 'spoiled',
    ])->assertStatus(422);
});

it('never takes from the breakdown on a count shortfall', function (): void {
    $ctx = makeMerchantActor();
    $branch = $ctx['branch'];
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0.001');
    $bottle = rvContainer($milk, 'bottle', '1000');
    rvStock($branch, $milk, '5000');

    $this->postJson("/api/branches/{$branch->uuid}/stock-counts", ['lines' => [[
        'ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '3']],
    ]]])->assertCreated();

    // 2 l short: a waste record, but the breakdown is what was counted.
    expect(DB::table('pos_waste_records')->where('reason', 'reconciliation_variance')->count())->toBe(1)
        ->and(rvBreakdownOf($milk, $branch))->toBe([$bottle => 3.0])
        ->and(DB::table('pos_stock_container_movements')->where('reason', 'waste')->count())->toBe(0);
});

it('puts a container on a restock line and moves it on allocation', function (): void {
    $ctx = makeMerchantActor();
    $branch = $ctx['branch'];
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1500');
    rvStock(null, $milk, '15000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '10');

    $req = $this->postJson("/api/branches/{$branch->uuid}/restock-requests", ['lines' => [[
        'ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '4',
    ]]])->assertCreated();
    expect($req->json('data.lines.0.quantity_requested'))->toBe('6000.000')
        ->and($req->json('data.lines.0.pieces'))->toBe('4')
        ->and($req->json('data.lines.0.container_label'))->toBe('bottle 1.5 l');

    $uuid = $req->json('data.uuid');
    $this->postJson("/api/restock-requests/{$uuid}/submit")->assertOk();
    $this->postJson("/api/restock-requests/{$uuid}/approve", [])->assertOk();
    $this->postJson("/api/restock-requests/{$uuid}/allocate", [])->assertOk();

    expect(rvBreakdownOf($milk, $branch))->toBe([$bottle => 4.0])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 6.0]);
});

it('keeps counts blind: the count form gets no breakdown', function (): void {
    // The count modal is filled from the ingredient list (no balances); the
    // breakdown rides only the stock list. Nothing in the count request
    // payload is pre-filled from the books (checked in the portal test too).
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    rvContainer($milk, 'bottle', '1000');
    $listed = collect($this->getJson('/api/ingredients')->json('data'))->firstWhere('uuid', $milk->uuid);
    expect($listed)->not->toHaveKey('breakdown')->and($listed)->not->toHaveKey('quantity');
    expect(StockCountLine::query()->count())->toBe(0);
});
