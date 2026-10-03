<?php

declare(strict_types=1);

/**
 * LAUNCH item kind (work order LAUNCH-P23, Part A — portal side).
 *
 * Owner decision 2026-10-03: an ingredient is asked what KIND of item it is —
 * Weighed (stored in g), Liquid (ml) or Counted (piece) — instead of a base
 * unit. No migration and no data conversion: the kind is read from the
 * stored unit (kg/g weighed, l/ml liquid, piece/pack/box counted), and the
 * server keeps accepting `unit` as before.
 *
 *   A2 the list says each ingredient's kind, and whether it can still change
 *      (the unit-change rule, worked out up front for the edit form);
 *   A3 optional pack sizes on create ("crate holds 12 l"), saved in the same
 *      request and transaction, with the factor worked out, never typed;
 *   A4 the pack-size endpoints take what a pack holds (amount + unit);
 *   A7 a portal stock count can be typed in any unit the item knows.
 * Follow-up fixes:
 *   F4 the warehouse endpoints take the unit the amounts were typed in;
 *   F5 restock allocations take a unit per line (suggestions already could).
 */

use App\Actions\Pos\Inventory\CreateIngredientAction;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\IngredientStock;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use App\Models\StockCountLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('A2 lists each ingredient kind from its stored unit, unconverted, and whether its kind can still change', function (): void {
    $ctx = makeMerchantActor();
    $units = ['g' => 'weighed', 'kg' => 'weighed', 'ml' => 'liquid', 'l' => 'liquid', 'piece' => 'counted', 'pack' => 'counted', 'box' => 'counted'];
    foreach (array_keys($units) as $unit) {
        p3Ingredient($ctx['company'], "Item {$unit}", $unit, '0.001');
    }
    // Used: a balance (with its movement), or a line in a prep recipe.
    $stocked = p3Ingredient($ctx['company'], 'Stocked rice', 'g', '0.001');
    p3Stock($ctx['branch'], $stocked, '500');
    $inPrep = p3Ingredient($ctx['company'], 'Sauce tomato', 'g', '0.002');
    p3Prep($ctx['company'], 'Sauce', 'ml', '1000', [[$inPrep, '200']]);

    $rows = collect($this->getJson('/api/ingredients')->assertOk()->json('data'))->keyBy('name');

    foreach ($units as $unit => $kind) {
        expect($rows["Item {$unit}"]['kind'])->toBe($kind);
        expect($rows["Item {$unit}"]['unit'])->toBe($unit);
        expect($rows["Item {$unit}"]['unit_locked'])->toBeFalse();
    }
    expect($rows['Stocked rice']['unit_locked'])->toBeTrue();
    expect($rows['Sauce tomato']['unit_locked'])->toBeTrue();
});

it('A2 an unused ingredient can change kind; a used one keeps refusing with the existing message', function (): void {
    $ctx = makeMerchantActor();
    $free = p3Ingredient($ctx['company'], 'Free', 'kg', '0.001');
    $this->patchJson("/api/ingredients/{$free->uuid}", ['unit' => 'ml'])
        ->assertOk()
        ->assertJsonPath('data.unit', 'ml')
        ->assertJsonPath('data.kind', 'liquid')
        ->assertJsonPath('data.unit_locked', false);

    $used = p3Ingredient($ctx['company'], 'Used', 'g', '0.001');
    p3Stock($ctx['branch'], $used, '10');
    $this->patchJson("/api/ingredients/{$used->uuid}", ['unit' => 'ml'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cannot change the unit of an ingredient that already has stock, movements, or recipe/add-on usage. Remove those references first, then create a new ingredient with the new unit.');
    $this->patchJson("/api/ingredients/{$used->uuid}", ['name' => 'Used rice'])
        ->assertOk()
        ->assertJsonPath('data.kind', 'weighed')
        ->assertJsonPath('data.unit_locked', true);
});

it('A3 creates Milk as Liquid with "crate holds 12 l" in one request, and 3 crates received add 36 l', function (): void {
    $ctx = makeMerchantActor();

    $created = $this->postJson('/api/ingredients', [
        'name' => 'Milk',
        'unit' => 'ml',
        'pack_sizes' => [['name' => 'crate', 'name_ar' => 'صندوق', 'amount' => '12', 'unit' => 'l']],
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'liquid')
        ->assertJsonPath('data.alt_units.0.name', 'crate')
        ->assertJsonPath('data.alt_units.0.name_ar', 'صندوق')
        ->assertJsonPath('data.alt_units.0.factor', '12000.0000')
        ->json('data');

    $this->postJson('/api/purchase-receipts', [
        'destination_branch_uuid' => $ctx['branch']->uuid,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $created['uuid'], 'quantity' => '3', 'unit' => 'crate', 'unit_price' => '4.800']],
    ])->assertCreated();

    expect((float) BranchStock::query()->where('branch_id', $ctx['branch']->id)->where('ingredient_id', $created['id'])->value('quantity'))->toBe(36000.0);
});

it('A3 works out each pack size factor from an amount in a unit of the kind', function (): void {
    makeMerchantActor();

    $rice = $this->postJson('/api/ingredients', [
        'name' => 'Rice', 'unit' => 'g',
        'pack_sizes' => [['name' => 'sack', 'amount' => '25', 'unit' => 'kg'], ['name' => 'bag', 'amount' => '500', 'unit' => 'g']],
    ])->assertCreated()->json('data');
    $cups = $this->postJson('/api/ingredients', [
        'name' => 'Cups', 'unit' => 'piece',
        'pack_sizes' => [['name' => 'box', 'amount' => '24', 'unit' => 'piece']],
    ])->assertCreated()->json('data');

    expect(IngredientAltUnit::query()->where('ingredient_id', $rice['id'])->orderBy('sort_order')->pluck('factor', 'name')->map(fn ($f) => (string) $f)->all())
        ->toBe(['sack' => '25000.0000', 'bag' => '500.0000']);
    expect((string) IngredientAltUnit::query()->where('ingredient_id', $cups['id'])->value('factor'))->toBe('24.0000');
});

it('A3 refuses a pack size outside the kind or named like a unit, and creates nothing', function (): void {
    makeMerchantActor();

    $this->postJson('/api/ingredients', [
        'name' => 'Oil', 'unit' => 'ml',
        'pack_sizes' => [['name' => 'tin', 'amount' => '5', 'unit' => 'kg']],
    ])->assertStatus(422)->assertJsonValidationErrors(['pack_sizes.0.unit']);

    $this->postJson('/api/ingredients', [
        'name' => 'Oil', 'unit' => 'ml',
        'pack_sizes' => [['name' => 'l', 'amount' => '1', 'unit' => 'l']],
    ])->assertStatus(422)->assertJsonValidationErrors(['pack_sizes.0.name']);

    $this->postJson('/api/ingredients', [
        'name' => 'Oil', 'unit' => 'ml',
        'pack_sizes' => [['name' => 'tin', 'amount' => '5', 'unit' => 'l'], ['name' => 'tin', 'amount' => '1', 'unit' => 'l']],
    ])->assertStatus(422)->assertJsonValidationErrors(['pack_sizes.1.name']);

    expect(Ingredient::query()->where('name', 'Oil')->exists())->toBeFalse();
    expect(IngredientAltUnit::query()->count())->toBe(0);
});

it('A3 saves the pack sizes in the same transaction: a refused one rolls the ingredient back', function (): void {
    $ctx = makeMerchantActor();

    expect(fn () => app(CreateIngredientAction::class)->handle([
        'name' => 'Syrup',
        'unit' => 'ml',
        'pack_sizes' => [
            ['name' => 'bottle', 'amount' => '1', 'unit' => 'l'],
            ['name' => 'ml', 'amount' => '1', 'unit' => 'ml'],
        ],
    ], $ctx['user']))->toThrow(RuntimeException::class);

    expect(Ingredient::query()->where('name', 'Syrup')->exists())->toBeFalse();
    expect(IngredientAltUnit::query()->count())->toBe(0);
});

it('A4 adds and re-sizes a pack size on an existing ingredient from what it holds, never a typed factor', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0004');

    $crate = $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'crate', 'amount' => '12', 'unit' => 'l'])
        ->assertCreated()
        ->assertJsonPath('data.factor', '12000.0000')
        ->json('data');
    $this->patchJson("/api/ingredients/{$milk->uuid}/units/{$crate['uuid']}", ['amount' => '500', 'unit' => 'ml', 'name_ar' => 'صندوق'])
        ->assertOk()
        ->assertJsonPath('data.factor', '500.0000')
        ->assertJsonPath('data.name_ar', 'صندوق');

    // An older kg ingredient: a 500 g bag is half a kg.
    $saffron = p3Ingredient($ctx['company'], 'Saffron', 'kg', '50');
    $this->postJson("/api/ingredients/{$saffron->uuid}/units", ['name' => 'bag', 'amount' => '500', 'unit' => 'g'])
        ->assertCreated()
        ->assertJsonPath('data.factor', '0.5000');
});

it('A4 refuses a pack size in a unit outside the kind, or named like a unit of the item', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0004');

    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'sack', 'amount' => '25', 'unit' => 'kg'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['unit']);
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'l', 'amount' => '1', 'unit' => 'l'])
        ->assertStatus(422)
        ->assertJsonPath('message', "'l' is already a unit of this item — give the pack its own name (crate, sack, box).");
    expect(IngredientAltUnit::query()->where('ingredient_id', $milk->id)->count())->toBe(0);
});

it('A7 a portal count is typed in any unit the item knows: kg, a pack size or the count container', function (): void {
    $ctx = makeMerchantActor();
    $rice = p3Ingredient($ctx['company'], 'Rice', 'g', '0.0005');
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0004');
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'crate', 'amount' => '12', 'unit' => 'l'])->assertCreated();
    $syrup = p3Ingredient($ctx['company'], 'Syrup', 'ml', '0.002', ['piece_unit_label' => 'bottle', 'units_per_piece' => '1500']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", ['lines' => [
        ['ingredient_uuid' => $rice->uuid, 'counted_units' => '2.5', 'unit' => 'kg'],
        ['ingredient_uuid' => $milk->uuid, 'counted_units' => '3', 'unit' => 'crate'],
        ['ingredient_uuid' => $syrup->uuid, 'counted_units' => '2', 'unit' => '@piece'],
    ]])->assertCreated();

    $line = fn (Ingredient $i) => StockCountLine::query()->where('ingredient_id', $i->id)->firstOrFail();
    expect((float) $line($rice)->counted_units)->toBe(2500.0);
    expect((float) $line($milk)->counted_units)->toBe(36000.0);
    // Containers are kept as pieces, as when counted in pieces.
    expect((float) $line($syrup)->counted_pieces)->toBe(2.0);
    expect((float) $line($syrup)->counted_units)->toBe(3000.0);
    expect((float) BranchStock::query()->where('branch_id', $ctx['branch']->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(36000.0);
});

it('A7 a count in a unit the item does not know is refused, and nothing is counted', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0004');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", ['lines' => [
        ['ingredient_uuid' => $milk->uuid, 'counted_units' => '3', 'unit' => 'kg'],
    ]])->assertStatus(422)->assertJsonPath('message', "Unit 'kg' is not defined for this ingredient.");
    expect(StockCountLine::query()->count())->toBe(0);
});

it('F4 the warehouse takes amounts in any unit the item knows: a pack size, l, the container', function (): void {
    $ctx = makeMerchantActor();
    config(['pos.inventory.single_stock_in' => false]);
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0004', ['piece_unit_label' => 'bottle', 'units_per_piece' => '1500']);
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'crate', 'amount' => '12', 'unit' => 'l'])->assertCreated();
    $other = Branch::factory()->for($ctx['company'], 'company')->create();
    $central = fn (): float => (float) IngredientStock::query()->where('ingredient_id', $milk->id)->value('quantity');
    $at = fn (Branch $b): float => (float) BranchStock::query()->where('branch_id', $b->id)->where('ingredient_id', $milk->id)->value('quantity');
    $base = "/api/ingredients/{$milk->uuid}/stock";

    $this->postJson("{$base}/receive", ['quantity' => '2', 'unit' => 'crate', 'no_cost' => true])->assertOk();
    expect($central())->toBe(24000.0);

    $this->postJson("{$base}/allocate", ['allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '1.5']], 'unit' => 'l'])->assertOk();
    expect($central())->toBe(22500.0)->and($at($ctx['branch']))->toBe(1500.0);

    $this->postJson("{$base}/transfer", ['from_branch_uuid' => $ctx['branch']->uuid, 'to_branch_uuid' => $other->uuid, 'quantity' => '1', 'unit' => '@piece'])->assertOk();
    expect($at($ctx['branch']))->toBe(0.0)->and($at($other))->toBe(1500.0);

    $this->postJson("{$base}/adjust", ['signed_quantity' => '-0.5', 'unit' => 'l', 'note' => 'Spilt'])->assertOk();
    expect($central())->toBe(22000.0);

    $this->postJson("{$base}/receive-distribute", [
        'quantity' => '12', 'unit' => 'l', 'no_cost' => true,
        'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '6']],
    ])->assertOk();
    expect($central())->toBe(28000.0)->and($at($ctx['branch']))->toBe(6000.0);
});

it('F4 a warehouse amount in a unit the item does not know is refused, and nothing moves', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0004');
    p3Stock($ctx['branch'], $milk, '1000');

    $this->postJson("/api/ingredients/{$milk->uuid}/stock/adjust", ['branch_uuid' => $ctx['branch']->uuid, 'signed_quantity' => '-1', 'unit' => 'kg', 'note' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('message', "Unit 'kg' is not defined for this ingredient.");
    expect((float) BranchStock::query()->where('branch_id', $ctx['branch']->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(1000.0);
});

it('F5 a restock allocation is typed in any unit of the line item (l, a pack size), capped at what was requested', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0004');
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'crate', 'amount' => '12', 'unit' => 'l'])->assertCreated();
    IngredientStock::query()->forceCreate(['company_id' => $ctx['company']->id, 'ingredient_id' => $milk->id, 'quantity' => '100000']);
    $request = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->approved()->create();
    $line = RestockRequestLine::factory()->for($request, 'request')->for($milk, 'ingredient')->create(['quantity_requested' => '36000', 'unit_at_set' => 'ml']);

    // 4 crates = 48 l is more than the 36 l requested.
    $this->postJson("/api/restock-requests/{$request->uuid}/allocate", ['allocations' => [(string) $line->id => '4'], 'units' => [(string) $line->id => 'crate']])
        ->assertStatus(422);

    $this->postJson("/api/restock-requests/{$request->uuid}/allocate", ['allocations' => [(string) $line->id => '30'], 'units' => [(string) $line->id => 'l']])
        ->assertOk();
    expect((float) $line->fresh()->quantity_allocated)->toBe(30000.0);
    expect((float) BranchStock::query()->where('branch_id', $ctx['branch']->id)->where('ingredient_id', $milk->id)->value('quantity'))->toBe(30000.0);
});

it('F5 restock suggestions turn into a request typed in l or a pack size', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0004');
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'crate', 'amount' => '12', 'unit' => 'l'])->assertCreated();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", ['lines' => [
        ['ingredient_uuid' => $milk->uuid, 'quantity_requested' => '3', 'unit' => 'crate'],
    ]])->assertCreated();
    expect((float) RestockRequestLine::query()->where('ingredient_id', $milk->id)->value('quantity_requested'))->toBe(36000.0);
});
