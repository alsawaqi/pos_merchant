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
 *      request and transaction, with the factor worked out, never typed.
 */

use App\Actions\Pos\Inventory\CreateIngredientAction;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
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
