<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 7 — C Purchases (owner decision D5,
 * tester calls 3 and 4).
 *
 *   - a line: item → container → pieces → amount (fills in as pieces × size;
 *     may be LOWERED, never RAISED) → the price paid for the line (required;
 *     0 = a free line: no expense, the average does not move);
 *   - cost per base unit = price ÷ amount (6 decimals, HALF_UP);
 *   - the split is entered in pieces; delivery to the warehouse, straight to
 *     a branch, or split, as today;
 *   - the breakdown: added at the warehouse in LEAF containers (2 crates of
 *     12 = 24 bottles), moved with each branch share;
 *   - a line with no container keeps the free amount (loose weight).
 */

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** Milk stored in ml with a 1 l bottle and a crate of 12 bottles. */
function rvMilkWithCrate(\App\Models\Company $company): array
{
    $milk = rvIngredient($company, 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $crate = rvContainer($milk, 'crate', '12000', $bottle, '12');

    return [$milk, $bottle, $crate];
}

it('receives 2 crates as 24 l = 24 bottles at the warehouse, costed per ml from the price paid', function (): void {
    $ctx = makeMerchantActor();
    [$milk, $bottle, $crate] = rvMilkWithCrate($ctx['company']);

    $res = $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid,
        'container_uuid' => rvContainerUuid($crate), 'pieces' => '2', 'line_cost' => '4.800',
    ]]])->assertCreated();

    $line = $res->json('data.lines.0');
    expect($line['quantity'])->toBe('24000.000')
        ->and($line['pieces'])->toBe('2')
        ->and($line['container_label'])->toBe('crate (12 × bottle 1 l)')
        ->and($line['container_factor'])->toBe('12000')
        ->and($line['line_cost'])->toBe('4.800')
        ->and($line['unit_cost'])->toBe('0.0002');

    expect((float) DB::table('pos_ingredient_stock')->where('ingredient_id', $milk->id)->value('quantity'))->toBe(24000.0)
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 24.0]);

    $row = DB::table('pos_purchase_receipt_lines')->first();
    expect((int) $row->container_id)->toBe($crate);

    // The ledger: one purchase row, balance = Σ delta.
    $ledger = DB::table('pos_stock_container_movements')->where('ingredient_id', $milk->id)->get();
    expect($ledger)->toHaveCount(1)
        ->and($ledger[0]->reason)->toBe('purchase')
        ->and((float) $ledger[0]->delta_pieces)->toBe(24.0)
        ->and($ledger[0]->reference_type)->toBe(\App\Models\PurchaseReceiptLine::class);
});

it('lets the amount be lowered (a broken bottle) but never raised', function (): void {
    $ctx = makeMerchantActor();
    [$milk, $bottle] = rvMilkWithCrate($ctx['company']);

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid,
        'container_uuid' => rvContainerUuid($bottle), 'pieces' => '12', 'amount' => '11', 'amount_unit' => 'l', 'line_cost' => '2.200',
    ]]])->assertCreated()->assertJsonPath('data.lines.0.quantity', '11000.000');

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid,
        'container_uuid' => rvContainerUuid($bottle), 'pieces' => '12', 'amount' => '12.5', 'amount_unit' => 'l', 'line_cost' => '2.200',
    ]]])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'never raised'));
});

it('requires the price paid on a container line and keeps a free line out of the books', function (): void {
    $ctx = makeMerchantActor();
    [$milk, $bottle] = rvMilkWithCrate($ctx['company']);

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '6',
    ]]])->assertStatus(422)->assertJsonValidationErrors(['lines.0.line_cost']);

    $expenses = DB::table('pos_expenses')->count();
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '6', 'line_cost' => '0',
    ]]])->assertCreated();
    expect(DB::table('pos_expenses')->count())->toBe($expenses)
        ->and((string) $milk->fresh()->default_unit_cost)->toBe('0.000')
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 6.0]);
});

it('splits in pieces and moves the breakdown with each branch share', function (): void {
    $ctx = makeMerchantActor();
    [$milk, $bottle, $crate] = rvMilkWithCrate($ctx['company']);
    $branch = $ctx['branch'];

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid,
        'container_uuid' => rvContainerUuid($crate), 'pieces' => '2', 'line_cost' => '4.800',
        'allocations' => [['branch_uuid' => $branch->uuid, 'pieces' => '1']],
    ]]])->assertCreated()->assertJsonPath('data.lines.0.allocations.0.pieces', '1');

    expect((float) DB::table('pos_branch_stock')->where('branch_id', $branch->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(12000.0)
        ->and(rvBreakdownOf($milk, $branch))->toBe([$bottle => 12.0])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 12.0]);

    // More pieces split than received is refused.
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid,
        'container_uuid' => rvContainerUuid($crate), 'pieces' => '1', 'line_cost' => '2.400',
        'allocations' => [['branch_uuid' => $branch->uuid, 'pieces' => '2']],
    ]]])->assertStatus(422);
});

it('lands the breakdown at the branch on a direct delivery', function (): void {
    $ctx = makeMerchantActor();
    [$milk, $bottle] = rvMilkWithCrate($ctx['company']);
    $branch = $ctx['branch'];

    $this->postJson('/api/purchase-receipts', [
        'destination_branch_uuid' => $branch->uuid,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '5', 'line_cost' => '1.000']],
    ])->assertCreated();

    expect(rvBreakdownOf($milk, $branch))->toBe([$bottle => 5.0])
        ->and(rvBreakdownOf($milk, null))->toBe([]);
});

it('keeps loose weight: a line with no container takes a free amount', function (): void {
    $ctx = makeMerchantActor();
    $tomato = rvIngredient($ctx['company'], 'Tomato', 'g');
    rvContainer($tomato, 'crate', '10000');

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '11.3', 'unit' => 'kg', 'line_cost' => '5.650',
    ]]])->assertCreated()->assertJsonPath('data.lines.0.quantity', '11300.000');
    expect(DB::table('pos_stock_container_balances')->count())->toBe(0);
});

it('never takes another item\'s container on a line', function (): void {
    $ctx = makeMerchantActor();
    [$milk] = rvMilkWithCrate($ctx['company']);
    $oil = rvIngredient($ctx['company'], 'Oil', 'ml');
    $drum = rvContainer($oil, 'drum', '20000');

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($drum), 'pieces' => '1', 'line_cost' => '1.000',
    ]]])->assertStatus(422);
    expect(DB::table('pos_purchase_receipts')->count())->toBe(0);
});
