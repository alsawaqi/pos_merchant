<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 6 — B the breakdown by container (owner
 * decision D4, tester calls 7 and 8).
 *
 *   - the stock pages show the live total AND the breakdown ("2 × bottle 1.5 l
 *     + 3 × bottle 500 ml"), with when it was last counted;
 *   - the breakdown is stored in leaf containers and is never used to compute
 *     stock: recipe use lowers only the total;
 *   - the warehouse "Correct containers" action (inventory.manage, HQ scope)
 *     sets the warehouse breakdown, audited and written to the ledger;
 *   - Distribute typed in a container ("2 crates") moves that breakdown.
 */

use App\Enums\MerchantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('shows the total and the breakdown on the branch stock list', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $big = rvContainer($milk, 'bottle', '1500');
    $small = rvContainer($milk, 'bottle', '500');
    rvStock($ctx['branch'], $milk, '4500');
    rvBreakdown($ctx['company'], $ctx['branch'], $milk, $big, '2');
    rvBreakdown($ctx['company'], $ctx['branch'], $milk, $small, '3');
    DB::table('pos_branch_stock')->where('ingredient_id', $milk->id)->update(['containers_counted_at' => '2026-10-04 10:00:00']);

    $row = collect($this->getJson("/api/branches/{$ctx['branch']->uuid}/stock")->assertOk()->json('data'))
        ->firstWhere('ingredient.uuid', $milk->uuid);

    expect($row['quantity'])->toBe('4500.000')
        ->and(collect($row['breakdown'])->map(static fn (array $b): string => $b['pieces'].' × '.$b['display_name'])->all())
        ->toBe(['2 × bottle 1.5 l', '3 × bottle 500 ml'])
        ->and($row['breakdown'][0]['amount'])->toBe('3000.000')
        ->and($row['containers_counted_at'])->toStartWith('2026-10-04');
});

it('shows the warehouse and branch breakdowns in the warehouse dialog', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    rvStock(null, $milk, '24000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '24');
    rvBreakdown($ctx['company'], $ctx['branch'], $milk, $bottle, '6');

    $data = $this->getJson("/api/ingredients/{$milk->uuid}/stock")->assertOk()->json('data');
    expect($data['central_breakdown'][0]['pieces'])->toBe('24')
        ->and($data['central_breakdown'][0]['display_name'])->toBe('bottle 1 l')
        ->and(collect($data['branches'])->firstWhere('branch_uuid', $ctx['branch']->uuid)['breakdown'][0]['pieces'])->toBe('6');
});

it('corrects the warehouse containers, audited and in the ledger, without moving the total', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $crate = rvContainer($milk, 'crate', '12000', $bottle, '12');
    $jug = rvContainer($milk, 'jug', '2000');
    rvStock(null, $milk, '30000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '30');

    $this->postJson("/api/ingredients/{$milk->uuid}/stock/containers", [
        'containers' => [
            ['container_uuid' => rvContainerUuid($crate), 'pieces' => '1'],
            ['container_uuid' => rvContainerUuid($jug), 'pieces' => '4'],
        ],
        'note' => 'shelf check',
    ])->assertOk()->assertJsonPath('data.central_quantity', '30000.000');

    // Leaf storage: 1 crate = 12 bottles; the jugs replace nothing else.
    expect(rvBreakdownOf($milk, null))->toBe([$bottle => 12.0, $jug => 4.0])
        ->and(DB::table('pos_stock_container_movements')->where('reason', 'correct')->count())->toBe(2)
        ->and(DB::table('pos_audit_logs')->where('event', 'inventory.containers.corrected')->count())->toBe(1)
        ->and(DB::table('pos_ingredient_stock')->where('ingredient_id', $milk->id)->value('containers_counted_at'))->not->toBeNull();

    // The ledger rows say what changed (30 → 12 bottles, 0 → 4 jugs).
    expect((float) DB::table('pos_stock_container_movements')->where('container_id', $bottle)->value('delta_pieces'))->toBe(-18.0)
        ->and((float) DB::table('pos_stock_container_movements')->where('container_id', $bottle)->value('pieces_after'))->toBe(12.0)
        ->and((float) DB::table('pos_stock_container_movements')->where('container_id', $jug)->value('delta_pieces'))->toBe(4.0);
});

it('keeps "Correct containers" to inventory managers with access to all branches', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');

    $this->postJson("/api/ingredients/{$milk->uuid}/stock/containers", ['containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '1']]])
        ->assertForbidden();
});

it('refuses another item\'s container in a correction', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $oil = rvIngredient($ctx['company'], 'Oil', 'ml');
    $drum = rvContainer($oil, 'drum', '20000');

    $this->postJson("/api/ingredients/{$milk->uuid}/stock/containers", ['containers' => [['container_uuid' => rvContainerUuid($drum), 'pieces' => '1']]])
        ->assertStatus(422);
});

it('moves the breakdown when Distribute is typed in a container', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $crate = rvContainer($milk, 'crate', '12000', $bottle, '12');
    rvStock(null, $milk, '24000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '24');

    $this->postJson("/api/ingredients/{$milk->uuid}/stock/allocate", [
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '1']],
        'unit' => rvToken($crate),
    ])->assertOk();

    expect((float) DB::table('pos_branch_stock')->where('ingredient_id', $milk->id)->value('quantity'))->toBe(12000.0)
        ->and(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 12.0])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 12.0]);
});

it('never computes stock from the breakdown', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    // Recipes used most of it: 1.2 l left in the books, 3 bottles last counted.
    rvStock($ctx['branch'], $milk, '1200');
    rvBreakdown($ctx['company'], $ctx['branch'], $milk, $bottle, '3');

    $row = collect($this->getJson("/api/branches/{$ctx['branch']->uuid}/stock")->json('data'))->firstWhere('ingredient.uuid', $milk->uuid);
    expect($row['quantity'])->toBe('1200.000')->and($row['breakdown'][0]['amount'])->toBe('3000.000');
});
