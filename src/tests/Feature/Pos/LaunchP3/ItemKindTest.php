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
 *      (the unit-change rule, worked out up front for the edit form).
 */

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
