<?php

declare(strict_types=1);

/*
 * LAUNCH review add-on — fix order B-1 (review R3 of part B). Each test
 * asserts the CORRECT behaviour; the reviewer's probes R3-P1…R3-P10 are
 * adopted here (R3-P8 / R3-P10 widened to every breakdown writer).
 */

use App\Actions\Pos\Catalogue\CreateProductAction;
use App\Actions\Pos\Catalogue\UpdateProductAction;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

if (! function_exists('rvLedgerHolds')) {
    /** Σ of the container ledger per (location, container) equals its balance, and no balance is negative. */
    function rvLedgerHolds(int $ingredientId): bool
    {
        $balances = DB::table('pos_stock_container_balances')->where('ingredient_id', $ingredientId)->get();
        foreach ($balances as $b) {
            if ((float) $b->pieces < 0) {
                return false;
            }
            $sum = (float) DB::table('pos_stock_container_movements')
                ->where('ingredient_id', $ingredientId)
                ->where('container_id', $b->container_id)
                ->when($b->branch_id === null, static fn ($q) => $q->whereNull('branch_id'), static fn ($q) => $q->where('branch_id', $b->branch_id))
                ->sum('delta_pieces');
            if (abs($sum - (float) $b->pieces) > 1e-9) {
                return false;
            }
        }

        return true;
    }

    /** Submit, approve and allocate a one-line restock request; returns the line id. */
    function rvRestockAllocate(\Tests\TestCase $test, \App\Models\Branch $branch, array $line, ?string $allocated): int
    {
        $req = $test->postJson("/api/branches/{$branch->uuid}/restock-requests", ['lines' => [$line]])->assertCreated();
        $uuid = $req->json('data.uuid');
        $lineId = (int) $req->json('data.lines.0.id');
        $test->postJson("/api/restock-requests/{$uuid}/submit")->assertOk();
        $test->postJson("/api/restock-requests/{$uuid}/approve", [])->assertOk();
        $test->postJson("/api/restock-requests/{$uuid}/allocate", $allocated === null ? [] : ['allocations' => [$lineId => $allocated]])->assertOk();

        return $lineId;
    }
}

// ---- M1 — restock allocation moves only whole containers -----------------

it('M1 (R3-P1) allocating 1 l of a 1-crate line moves exactly 1 bottle, never 0.9996', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $crate = rvContainer($milk, 'crate', '12000', $bottle, '12');
    rvStock(null, $milk, '12000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '12');

    rvRestockAllocate($this, $ctx['branch'], ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '1'], '1000');

    expect(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 1.0])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 11.0]);
});

it('M1 (R3-P1b) a share that is not whole containers of a whole-only item moves the total only, and says so', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0', ['allow_fractional_pieces' => false]);
    $bottle = rvContainer($milk, 'bottle', '1000');
    rvStock(null, $milk, '10000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '10');

    $lineId = rvRestockAllocate($this, $ctx['branch'], ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '2'], '1500');

    // The total moved; the breakdown did not (no 1.5 bottle anywhere).
    expect((float) DB::table('pos_branch_stock')->where('branch_id', $ctx['branch']->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(1500.0)
        ->and(rvBreakdownOf($milk, $ctx['branch']))->toBe([])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 10.0]);
    $note = (string) DB::table('pos_stock_movements')->where('reference_type', \App\Models\RestockRequestLine::class)->where('reference_id', $lineId)->where('branch_id', $ctx['branch']->id)->value('note');
    expect($note)->toContain('total only');
    $audit = DB::table('pos_audit_logs')->where('event', 'inventory.restock_request.allocated')->latest('id')->value('new_values');
    expect((string) $audit)->toContain('containers_not_moved');
});

it('M1 a whole number of containers of a partial share still moves (2 of a 3-bottle line)', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0', ['allow_fractional_pieces' => false]);
    $bottle = rvContainer($milk, 'bottle', '1000');
    rvStock(null, $milk, '5000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '5');

    rvRestockAllocate($this, $ctx['branch'], ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '3'], '2000');

    expect(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 2.0])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 3.0]);
});

it('M1 an inexact share of an item that allows part containers moves the total only too', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    rvStock(null, $milk, '3000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '3');

    // 3 bottles asked, 1000 ml sent: 1 bottle exactly; then 1 of 3 bottles = 333.3333 ml sent would be 0.33333… bottles.
    rvRestockAllocate($this, $ctx['branch'], ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '3'], '1000');
    expect(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 1.0]);

    $milk2 = rvIngredient($ctx['company'], 'Cream', 'ml');
    $tub = rvContainer($milk2, 'tub', '3000');
    rvStock(null, $milk2, '3000');
    rvBreakdown($ctx['company'], null, $milk2, $tub, '1');
    rvRestockAllocate($this, $ctx['branch'], ['ingredient_uuid' => $milk2->uuid, 'container_uuid' => rvContainerUuid($tub), 'pieces' => '1'], '1000');
    expect(rvBreakdownOf($milk2, $ctx['branch']))->toBe([])
        ->and(rvBreakdownOf($milk2, null))->toBe([$tub => 1.0]);
});

// ---- M2 — barcodes of deleted items / containers / packs ------------------

it('M2 (R3-P2) a deleted ingredient\'s barcode can be linked to its replacement', function (): void {
    $ctx = makeMerchantActor();
    $old = $this->postJson('/api/ingredients', ['name' => 'Old milk', 'unit' => 'ml', 'barcodes' => ['6291000000017']])->assertCreated();
    $this->deleteJson('/api/ingredients/'.$old->json('data.uuid'))->assertSuccessful();
    $new = rvIngredient($ctx['company'], 'New milk', 'ml');

    expect(DB::table('pos_item_barcodes')->where('barcode', '6291000000017')->whereNull('deleted_at')->count())->toBe(0);
    $this->postJson('/api/inventory/scan/link', ['code' => '6291000000017', 'item_type' => 'ingredient', 'item_uuid' => $new->uuid])->assertCreated()
        ->assertJsonPath('data.item.uuid', $new->uuid);
});

it('M2 (R3-P2b) a deleted physical item\'s barcode can be reused', function (): void {
    $ctx = makeMerchantActor();
    $cup = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cup', 'is_internal' => true, 'stock_mode' => 'unit', 'base_price' => 0]);
    $this->postJson('/api/inventory/barcodes', ['barcode' => '777', 'item_type' => 'physical', 'item_uuid' => $cup->uuid])->assertCreated();
    $this->deleteJson("/api/physical-items/{$cup->uuid}")->assertSuccessful();
    $lid = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Lid', 'is_internal' => true, 'stock_mode' => 'unit', 'base_price' => 0]);
    $this->postJson('/api/inventory/barcodes', ['barcode' => '777', 'item_type' => 'physical', 'item_uuid' => $lid->uuid])->assertCreated();
});

it('M2 a code left live on an already-deleted item (older data) never names it and is released on link', function (): void {
    $ctx = makeMerchantActor();
    $gone = rvIngredient($ctx['company'], 'Gone milk', 'ml');
    DB::table('pos_item_barcodes')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $ctx['company']->id, 'barcode' => '5000000000001', 'ingredient_id' => $gone->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_ingredients')->where('id', $gone->id)->update(['deleted_at' => now()]);
    $new = rvIngredient($ctx['company'], 'Milk', 'ml');

    expect(\App\Support\Inventory\ItemCodes::barcodeOwner((int) $ctx['company']->id, '5000000000001'))->toBeNull();
    $res = $this->postJson('/api/inventory/scan/link', ['code' => '5000000000001', 'item_type' => 'ingredient', 'item_uuid' => $new->uuid]);
    expect($res->status())->toBe(201)
        ->and((string) $res->json('message'))->not->toContain('Gone milk');
});

it('M2 a code on another live item is refused with that barcode row, which the portal may remove to move it', function (): void {
    $ctx = makeMerchantActor();
    $a = rvIngredient($ctx['company'], 'Milk A', 'ml');
    $b = rvIngredient($ctx['company'], 'Milk B', 'ml');
    $first = $this->postJson('/api/inventory/barcodes', ['barcode' => '4000000000002', 'item_type' => 'ingredient', 'item_uuid' => $a->uuid])->assertCreated();

    $clash = $this->postJson('/api/inventory/barcodes', ['barcode' => '4000000000002', 'item_type' => 'ingredient', 'item_uuid' => $b->uuid])->assertStatus(422);
    expect($clash->json('barcode_uuid'))->toBe($first->json('data.uuid'))
        ->and((string) $clash->json('message'))->toContain('Milk A');

    $this->deleteJson('/api/inventory/barcodes/'.$clash->json('barcode_uuid'))->assertNoContent();
    $this->postJson('/api/inventory/barcodes', ['barcode' => '4000000000002', 'item_type' => 'ingredient', 'item_uuid' => $b->uuid])->assertCreated();
});

it('M2 deleting a container or a pack forgets its barcodes', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $this->postJson('/api/inventory/barcodes', ['barcode' => '3000000000003', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle)])->assertCreated();
    $this->deleteJson("/api/ingredients/{$milk->uuid}/units/".rvContainerUuid($bottle))->assertSuccessful();
    $this->getJson('/api/inventory/scan?code=3000000000003')->assertJsonPath('data.found', false);
});

// ---- M3 — the count container is size-locked ------------------------------

it('M3 (R3-P4) the container tills count in is size-locked, counted in or not', function (): void {
    makeMerchantActor();
    $created = $this->postJson('/api/ingredients', [
        'name' => 'Rice', 'unit' => 'g',
        'pack_sizes' => [
            ['name' => 'bag', 'amount' => '1', 'unit' => 'kg', 'count_container' => true],
            ['name' => 'sack', 'amount' => '25', 'unit' => 'kg'],
        ],
    ])->assertCreated();
    $uuid = $created->json('data.uuid');
    $bag = collect($created->json('data.alt_units'))->firstWhere('name', 'bag');
    $sack = collect($created->json('data.alt_units'))->firstWhere('name', 'sack');
    $listed = collect($this->getJson("/api/ingredients/{$uuid}/units")->assertOk()->json('data'));
    expect($listed->firstWhere('uuid', $bag['uuid'])['size_locked'])->toBeTrue()
        ->and($listed->firstWhere('uuid', $sack['uuid'])['size_locked'])->toBeFalse();

    $this->patchJson("/api/ingredients/{$uuid}/units/{$bag['uuid']}", ['amount' => '2', 'unit' => 'kg'])->assertStatus(422);
    expect((float) DB::table('pos_ingredients')->where('uuid', $uuid)->value('units_per_piece'))->toBe(1000.0);

    // The marker moves to the sack: the sack is locked now, the bag (never used) is free again.
    $this->patchJson("/api/ingredients/{$uuid}", ['count_container_uuid' => $sack['uuid']])->assertOk();
    $this->patchJson("/api/ingredients/{$uuid}/units/{$sack['uuid']}", ['amount' => '20', 'unit' => 'kg'])->assertStatus(422);
    $this->patchJson("/api/ingredients/{$uuid}/units/{$bag['uuid']}", ['amount' => '2', 'unit' => 'kg'])->assertOk();
});

it('M3 a container counted by container stays locked after the marker moves away', function (): void {
    $ctx = makeMerchantActor();
    $created = $this->postJson('/api/ingredients', [
        'name' => 'Rice', 'unit' => 'g',
        'pack_sizes' => [['name' => 'bag', 'amount' => '1', 'unit' => 'kg', 'count_container' => true], ['name' => 'sack', 'amount' => '25', 'unit' => 'kg']],
    ])->assertCreated();
    $uuid = $created->json('data.uuid');
    $bag = collect($created->json('data.alt_units'))->firstWhere('name', 'bag');
    $sack = collect($created->json('data.alt_units'))->firstWhere('name', 'sack');
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", ['lines' => [['ingredient_uuid' => $uuid, 'containers' => [['container_uuid' => $bag['uuid'], 'pieces' => '3']]]]])->assertCreated();
    $this->patchJson("/api/ingredients/{$uuid}", ['count_container_uuid' => $sack['uuid']])->assertOk();
    $this->patchJson("/api/ingredients/{$uuid}/units/{$bag['uuid']}", ['amount' => '2', 'unit' => 'kg'])->assertStatus(422);
});

// ---- M4 — the piece_* mirror only through the count container -------------

it('M4 (R3-P3) a changed piece_* pair is refused without a count container; unchanged values pass', function (): void {
    $ctx = makeMerchantActor();
    $rice = rvIngredient($ctx['company'], 'Rice', 'g');
    $this->patchJson("/api/ingredients/{$rice->uuid}", ['piece_unit_label' => 'bag', 'units_per_piece' => '5000'])
        ->assertStatus(422)->assertJsonValidationErrors(['piece_unit_label', 'units_per_piece']);
    $rice->refresh();
    expect($rice->piece_unit_label)->toBeNull()->and($rice->count_container_id)->toBeNull();

    // What an old tab sends back unchanged still saves.
    $this->patchJson("/api/ingredients/{$rice->uuid}", ['name' => 'Rice (basmati)', 'piece_unit_label' => null, 'units_per_piece' => null, 'allow_fractional_pieces' => true])->assertOk();
});

it('M4 allow_fractional_pieces changes only together with the count container', function (): void {
    makeMerchantActor();
    $created = $this->postJson('/api/ingredients', [
        'name' => 'Eggs', 'unit' => 'piece',
        'pack_sizes' => [['name' => 'tray', 'amount' => '30', 'unit' => 'piece', 'count_container' => true]],
    ])->assertCreated();
    $uuid = $created->json('data.uuid');
    $tray = collect($created->json('data.alt_units'))->firstWhere('name', 'tray');

    $this->patchJson("/api/ingredients/{$uuid}", ['allow_fractional_pieces' => false])
        ->assertStatus(422)->assertJsonValidationErrors(['allow_fractional_pieces']);
    $this->patchJson("/api/ingredients/{$uuid}", ['count_container_uuid' => $tray['uuid'], 'allow_fractional_pieces' => false])->assertOk();
    expect((bool) Ingredient::query()->where('uuid', $uuid)->value('allow_fractional_pieces'))->toBeFalse();
    $this->patchJson("/api/ingredients/{$uuid}", ['piece_unit_label' => 'box', 'units_per_piece' => '12'])->assertStatus(422);
});

// ---- L1 — container lines respect the same maximums ----------------------

it('L1 (R3-P5) a container purchase line respects the 999,999,999.9999 bound like the loose line', function (): void {
    $ctx = makeMerchantActor();
    $water = rvIngredient($ctx['company'], 'Water', 'ml');
    $tank = rvContainer($water, 'tank', '1000000');

    $loose = $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $water->uuid, 'quantity' => '2000', 'unit' => rvToken($tank), 'line_cost' => '1',
    ]]]);
    $byContainer = $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $water->uuid, 'container_uuid' => rvContainerUuid($tank), 'pieces' => '2000', 'line_cost' => '1',
    ]]]);
    expect($loose->status())->toBe(422)
        ->and($byContainer->status())->toBe(422)
        ->and(DB::table('pos_ingredient_stock')->where('ingredient_id', $water->id)->value('quantity'))->toBeNull();

    // Transfers, waste, counts and restock go through the same check.
    rvStock($ctx['branch'], $water, '1000');
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", ['ingredient_uuid' => $water->uuid, 'container_uuid' => rvContainerUuid($tank), 'pieces' => '2000', 'reason' => 'spoiled'])->assertStatus(422);
});

it('L1 a pack purchase line respects the 999,999.999 pieces of a loose line', function (): void {
    $ctx = makeMerchantActor();
    $cup = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cup', 'is_internal' => true, 'stock_mode' => 'unit', 'base_price' => 0]);
    $pack = $this->postJson("/api/physical-items/{$cup->uuid}/packs", ['name' => 'pallet', 'pieces' => '100000'])->assertCreated();
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'product', 'item_uuid' => $cup->uuid, 'pack_uuid' => $pack->json('data.uuid'), 'pieces' => '20', 'line_cost' => '1',
    ]]])->assertStatus(422);
});

// ---- L2 — the SKU generator ignores codes it could not have made ---------

it('L2 (R3-P6) a typed SKU like ING-99999999999999999999 or ING-12A never breaks the generator', function (): void {
    makeMerchantActor();
    $this->postJson('/api/ingredients', ['name' => 'A', 'unit' => 'g', 'sku' => 'ING-99999999999999999999'])->assertCreated();
    $this->postJson('/api/ingredients', ['name' => 'B', 'unit' => 'g', 'sku' => 'ING-0007A'])->assertCreated();
    $this->postJson('/api/ingredients', ['name' => 'C', 'unit' => 'g', 'sku' => 'ING-0005'])->assertCreated();
    $res = $this->postJson('/api/ingredients', ['name' => 'D', 'unit' => 'g'])->assertCreated();
    expect($res->json('data.sku'))->toBe('ING-0006');
});

// ---- L3 — barcodes are case-insensitive ----------------------------------

it('L3 (R3-P7) barcodes are unique and matched case-insensitively, stored as typed', function (): void {
    $ctx = makeMerchantActor();
    $a = rvIngredient($ctx['company'], 'A', 'g');
    $b = rvIngredient($ctx['company'], 'B', 'g');
    $this->postJson('/api/inventory/barcodes', ['barcode' => 'ABC-1', 'item_type' => 'ingredient', 'item_uuid' => $a->uuid])->assertCreated();
    $this->postJson('/api/inventory/barcodes', ['barcode' => 'abc-1', 'item_type' => 'ingredient', 'item_uuid' => $b->uuid])->assertStatus(422);
    $this->getJson('/api/inventory/scan?code=abc-1')->assertJsonPath('data.item.uuid', $a->uuid);
    expect(DB::table('pos_item_barcodes')->whereNull('deleted_at')->value('barcode'))->toBe('ABC-1');

    // Against a product's own barcode too.
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cola', 'barcode' => 'XY-9', 'stock_mode' => 'unit']);
    $this->postJson('/api/inventory/barcodes', ['barcode' => 'xy-9', 'item_type' => 'ingredient', 'item_uuid' => $b->uuid])->assertStatus(422);
});

// ---- L4 — a container picked in the unit list --------------------------

it('L4 (R3-P9) waste typed in a container unit takes that container, like a transfer moves it', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    rvStock($ctx['branch'], $milk, '5000');
    rvBreakdown($ctx['company'], $ctx['branch'], $milk, $bottle, '5');
    $res = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", ['ingredient_uuid' => $milk->uuid, 'quantity' => '2', 'unit' => rvToken($bottle), 'reason' => 'spoiled'])->assertSuccessful();
    expect(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 3.0])
        ->and((float) DB::table('pos_branch_stock')->where('ingredient_id', $milk->id)->value('quantity'))->toBe(3000.0)
        ->and($res->json('data.pieces'))->not->toBeNull();
});

it('L4 a count typed in a container unit sets the breakdown, like counting by container', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $small = rvContainer($milk, 'small bottle', '500');
    rvStock($ctx['branch'], $milk, '5000');
    rvBreakdown($ctx['company'], $ctx['branch'], $milk, $small, '4');
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", ['lines' => [['ingredient_uuid' => $milk->uuid, 'counted_units' => '3', 'unit' => rvToken($bottle)]]])->assertCreated();
    expect(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 3.0])
        ->and((float) DB::table('pos_branch_stock')->where('ingredient_id', $milk->id)->value('quantity'))->toBe(3000.0);
});

it('L4 a restock line typed in a container unit names the container, so the allocation moves it', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    rvStock(null, $milk, '4000');
    rvBreakdown($ctx['company'], null, $milk, $bottle, '4');
    rvRestockAllocate($this, $ctx['branch'], ['ingredient_uuid' => $milk->uuid, 'quantity_requested' => '2', 'unit' => rvToken($bottle)], null);
    expect(rvBreakdownOf($milk, $ctx['branch']))->toBe([$bottle => 2.0])
        ->and(rvBreakdownOf($milk, null))->toBe([$bottle => 2.0]);
});

// ---- L5 — product, combo and import saves claim their codes --------------

it('L5 a product save re-checks its SKU and barcode across tables inside its own transaction', function (): void {
    $ctx = makeMerchantActor();
    rvIngredient($ctx['company'], 'Flour', 'g', '0', ['sku' => 'X1']);
    $owner = rvIngredient($ctx['company'], 'Sugar', 'g');
    DB::table('pos_item_barcodes')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $ctx['company']->id, 'barcode' => 'BC-7', 'ingredient_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);

    // Straight through the action (the request check is the earlier gate; a concurrent save passes it).
    expect(fn () => app(CreateProductAction::class)->handle(['name' => 'Latte', 'base_price' => '1.000', 'sku' => 'x1', 'stock_mode' => 'untracked'], $ctx['user']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CreateProductAction::class)->handle(['name' => 'Mocha', 'base_price' => '1.000', 'barcode' => 'bc-7', 'stock_mode' => 'untracked'], $ctx['user']))
        ->toThrow(ValidationException::class);
    $product = app(CreateProductAction::class)->handle(['name' => 'Tea', 'base_price' => '1.000', 'sku' => 'T1', 'stock_mode' => 'untracked'], $ctx['user']);
    expect(fn () => app(UpdateProductAction::class)->handle($product, ['sku' => 'X1'], $ctx['user']))
        ->toThrow(ValidationException::class);
    expect(Product::query()->where('name', 'Latte')->exists())->toBeFalse();
});

// ---- L9 — "#" names keep resolving ----------------------------------------

it('L9 an older container named "#10 can" still resolves by its name', function (): void {
    $ctx = makeMerchantActor();
    $beans = rvIngredient($ctx['company'], 'Beans', 'g');
    $can = rvContainer($beans, '#10 can', '3000');
    rvStock($ctx['branch'], $beans, '9000');
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", ['ingredient_uuid' => $beans->uuid, 'quantity' => '1', 'unit' => '#10 can', 'reason' => 'spoiled'])->assertSuccessful();
    expect((float) DB::table('pos_branch_stock')->where('ingredient_id', $beans->id)->value('quantity'))->toBe(6000.0)
        ->and(\App\Support\Inventory\Containers::resolve($beans->fresh(), '#10 can')?->id)->toBe($can);
});

// ---- L10 — a scan never offers what Purchases refuses ---------------------

it('L10 the scan says when an item cannot be bought, and a stock barcode never goes on a cooked or combo product', function (): void {
    $ctx = makeMerchantActor();
    $prep = rvIngredient($ctx['company'], 'Syrup', 'ml', '0', ['is_prep' => true, 'sku' => 'PREP-1']);
    $this->getJson('/api/inventory/scan?code=PREP-1')->assertJsonPath('data.purchasable', false)->assertJsonPath('data.not_purchasable_reason', 'prep');
    $combo = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Meal', 'barcode' => 'MEAL-1', 'stock_mode' => 'untracked', 'product_type' => 'combo']);
    $this->getJson('/api/inventory/scan?code=MEAL-1')->assertJsonPath('data.purchasable', false)->assertJsonPath('data.not_purchasable_reason', 'not_bought_in');
    $cola = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cola', 'barcode' => 'COLA-1', 'stock_mode' => 'unit']);
    $this->getJson('/api/inventory/scan?code=COLA-1')->assertJsonPath('data.purchasable', true);
    $cooked = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Rice dish', 'stock_mode' => 'cooked']);
    $this->postJson('/api/inventory/scan/link', ['code' => '9990001', 'item_type' => 'product', 'item_uuid' => $cooked->uuid])->assertStatus(422);
    $this->postJson('/api/inventory/scan/link', ['code' => '9990002', 'item_type' => 'product', 'item_uuid' => $combo->uuid])->assertStatus(422);
    $this->postJson('/api/inventory/scan/link', ['code' => '9990003', 'item_type' => 'product', 'item_uuid' => $cola->uuid])->assertCreated();
    expect($prep->is_prep)->toBeTrue();
});

// ---- T3 — the ledger rule across every breakdown writer -------------------

it('T3 (R3-P8) purchases split and direct-to-branch keep stock = Σ movements and breakdown = Σ ledger', function (): void {
    $ctx = makeMerchantActor();
    $b1 = $ctx['branch'];
    $b2 = rvBranch($ctx['company']);
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $crate = rvContainer($milk, 'crate', '12000', $bottle, '12');

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '3',
        'amount' => '35.5', 'amount_unit' => 'l', 'line_cost' => '10',
        'allocations' => [['branch_uuid' => $b1->uuid, 'pieces' => '1'], ['branch_uuid' => $b2->uuid, 'pieces' => '1']],
    ]]])->assertCreated();
    $this->postJson('/api/purchase-receipts', ['destination_branch_uuid' => $b2->uuid, 'lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '7', 'amount' => '6.5', 'amount_unit' => 'l', 'line_cost' => '0',
    ]]])->assertCreated();

    $central = (float) DB::table('pos_ingredient_stock')->where('ingredient_id', $milk->id)->value('quantity');
    $centralMoves = (float) DB::table('pos_stock_movements')->where('ingredient_id', $milk->id)->whereNull('branch_id')->sum('quantity');
    $b1q = (float) DB::table('pos_branch_stock')->where('branch_id', $b1->id)->where('ingredient_id', $milk->id)->value('quantity');
    $b2q = (float) DB::table('pos_branch_stock')->where('branch_id', $b2->id)->where('ingredient_id', $milk->id)->value('quantity');
    $b1m = (float) DB::table('pos_stock_movements')->where('ingredient_id', $milk->id)->where('branch_id', $b1->id)->sum('quantity');
    $b2m = (float) DB::table('pos_stock_movements')->where('ingredient_id', $milk->id)->where('branch_id', $b2->id)->sum('quantity');
    expect(abs($central - $centralMoves))->toBeLessThan(1e-9)
        ->and(abs($b1q - $b1m))->toBeLessThan(1e-9)
        ->and(abs($b2q - $b2m))->toBeLessThan(1e-9)
        ->and(abs($central + $b1q + $b2q - 42000))->toBeLessThan(1e-9)
        ->and(rvLedgerHolds((int) $milk->id))->toBeTrue();
});

it('T3 (R3-P10, widened) every breakdown writer keeps balance = Σ ledger and never goes negative', function (): void {
    $ctx = makeMerchantActor();
    $b1 = $ctx['branch'];
    $b2 = rvBranch($ctx['company']);
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $crate = rvContainer($milk, 'crate', '12000', $bottle, '12');
    // Purchase (add + split move).
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '3', 'line_cost' => '24',
        'allocations' => [['branch_uuid' => $b1->uuid, 'pieces' => '1']],
    ]]])->assertCreated();
    // Transfer by container with a source clamp, and one typed in a container unit.
    $this->postJson("/api/branches/{$b1->uuid}/transfers", ['to_branch_uuid' => $b2->uuid, 'lines' => [['ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '12']], 'quantity' => '12', 'unit' => 'l']]])->assertCreated();
    rvStock($b1, $milk, '5000');
    // Waste by container (clamp) and in a container unit.
    $this->postJson("/api/branches/{$b1->uuid}/waste", ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '3', 'reason' => 'spoiled'])->assertSuccessful();
    $this->postJson("/api/branches/{$b2->uuid}/waste", ['ingredient_uuid' => $milk->uuid, 'quantity' => '1', 'unit' => rvToken($bottle), 'reason' => 'spoiled'])->assertSuccessful();
    // Counts: by container, and in a container unit.
    $this->postJson("/api/branches/{$b2->uuid}/stock-counts", ['lines' => [['ingredient_uuid' => $milk->uuid, 'containers' => [['container_uuid' => rvContainerUuid($bottle), 'pieces' => '10']]]]])->assertCreated();
    $this->postJson("/api/branches/{$b1->uuid}/stock-counts", ['lines' => [['ingredient_uuid' => $milk->uuid, 'counted_units' => '2', 'unit' => rvToken($bottle)]]])->assertCreated();
    // Restock allocations: whole, and a share that is not whole containers.
    rvRestockAllocate($this, $b1, ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'pieces' => '2'], null);
    rvRestockAllocate($this, $b2, ['ingredient_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($crate), 'pieces' => '1'], '1500');
    // Warehouse correction.
    $this->postJson("/api/ingredients/{$milk->uuid}/stock/containers", ['containers' => [['container_uuid' => rvContainerUuid($crate), 'pieces' => '1'], ['container_uuid' => rvContainerUuid($bottle), 'pieces' => '0.5']]])->assertOk();

    expect(DB::table('pos_stock_container_balances')->where('pieces', '<', 0)->count())->toBe(0)
        ->and(rvLedgerHolds((int) $milk->id))->toBeTrue();
});
