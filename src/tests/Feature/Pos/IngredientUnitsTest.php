<?php

declare(strict_types=1);

/**
 * v2 #13 — per-ingredient alternate units + the convert-to-base helper.
 *
 *   GET/POST/PATCH/DELETE /api/ingredients/{uuid}/units  (inventory.view/manage)
 *
 * Storage stays in the ingredient's BASE unit; an alt unit carries a `factor`
 * (base units per 1 of it). IngredientUnitConverter turns an entered (qty, unit)
 * into base units — the boundary the entry flows convert at (Phase 2).
 */

use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeIngredient(Company $company, string $unit = 'g'): Ingredient
{
    return Ingredient::factory()->for($company, 'company')->create(['unit' => $unit]);
}

it('creates an alternate unit with a factor', function (): void {
    $ctx = makeMerchantActor();
    $ing = makeIngredient($ctx['company']); // base = g

    $res = $this->postJson("/api/ingredients/{$ing->uuid}/units", [
        'name' => 'crate', 'factor' => '5000',
    ])->assertCreated();

    expect($res->json('data.name'))->toBe('crate');
    expect($res->json('data.factor'))->toBe('5000.0000');
    expect(IngredientAltUnit::where('ingredient_id', $ing->id)->where('name', 'crate')->exists())->toBeTrue();
});

it('lists an ingredient units and surfaces them on the ingredient resource', function (): void {
    $ctx = makeMerchantActor();
    $ing = makeIngredient($ctx['company']);
    $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'crate', 'factor' => '1000'])->assertCreated();
    $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'box', 'factor' => '5000'])->assertCreated();

    $rows = $this->getJson("/api/ingredients/{$ing->uuid}/units")->assertOk()->json('data');
    expect(collect($rows)->pluck('name')->all())->toContain('crate', 'box');

    // The ingredient list eager-loads alt_units.
    $listed = collect($this->getJson('/api/ingredients')->json('data'))->firstWhere('uuid', $ing->uuid);
    expect(collect($listed['alt_units'])->pluck('name')->all())->toContain('crate', 'box');
});

it('refuses an alt unit named the same as the base unit', function (): void {
    $ctx = makeMerchantActor();
    $ing = makeIngredient($ctx['company'], 'g');

    $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'g', 'factor' => '1'])
        ->assertStatus(422);
});

it('refuses a non-positive factor', function (): void {
    $ctx = makeMerchantActor();
    $ing = makeIngredient($ctx['company']);

    $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'kg', 'factor' => '0'])->assertStatus(422);
    $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'kg', 'factor' => '-3'])->assertStatus(422);
});

it('refuses a duplicate active unit name', function (): void {
    $ctx = makeMerchantActor();
    $ing = makeIngredient($ctx['company']);
    $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'crate', 'factor' => '1000'])->assertCreated();

    $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'crate', 'factor' => '999'])->assertStatus(422);
});

it('restores a soft-deleted unit when its name is re-created', function (): void {
    $ctx = makeMerchantActor();
    $ing = makeIngredient($ctx['company']);
    $created = $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'crate', 'factor' => '1000'])->json('data');
    $this->deleteJson("/api/ingredients/{$ing->uuid}/units/{$created['uuid']}")->assertNoContent();

    // Re-creating the same name succeeds (restores) rather than colliding.
    $again = $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'crate', 'factor' => '1200'])->assertCreated();
    expect($again->json('data.factor'))->toBe('1200.0000');
    expect(IngredientAltUnit::where('ingredient_id', $ing->id)->where('name', 'crate')->count())->toBe(1);
});

it('updates a unit factor', function (): void {
    $ctx = makeMerchantActor();
    $ing = makeIngredient($ctx['company']);
    $created = $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'crate', 'factor' => '1000'])->json('data');

    $res = $this->patchJson("/api/ingredients/{$ing->uuid}/units/{$created['uuid']}", ['factor' => '1001'])->assertOk();
    expect($res->json('data.factor'))->toBe('1001.0000');
});

it('does not leak another tenant ingredient (404)', function (): void {
    makeMerchantActor();
    $foreign = makeIngredient(Company::factory()->create());

    $this->getJson("/api/ingredients/{$foreign->uuid}/units")->assertNotFound();
    $this->postJson("/api/ingredients/{$foreign->uuid}/units", ['name' => 'kg', 'factor' => '1000'])->assertNotFound();
});

it('gates create behind inventory.manage', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $ing = makeIngredient($ctx['company']);

    $this->getJson("/api/ingredients/{$ing->uuid}/units")->assertOk(); // view allowed
    $this->postJson("/api/ingredients/{$ing->uuid}/units", ['name' => 'kg', 'factor' => '1000'])->assertForbidden();
});

it('converts entered quantities to the base unit', function (): void {
    $ctx = makeMerchantActor();
    $ing = makeIngredient($ctx['company'], 'g');
    IngredientAltUnit::query()->create([
        'company_id' => $ctx['company']->id, 'ingredient_id' => $ing->id, 'name' => 'kg', 'factor' => '1000',
    ]);

    $converter = new IngredientUnitConverter();

    expect($converter->toBase($ing, 250))->toBe(250.0);          // null = base (g)
    expect($converter->toBase($ing, 250, 'g'))->toBe(250.0);     // explicit base
    expect($converter->toBase($ing, 2, 'kg'))->toBe(2000.0);     // 2 kg → 2000 g
    expect(fn () => $converter->toBase($ing, 1, 'lb'))->toThrow(RuntimeException::class);
});

it('refuses an alt unit whose name is an auto metric sibling (PD4)', function (): void {
    $ctx = makeMerchantActor();

    // base g -> 'kg' is provided automatically; a manual duplicate is refused.
    $g = makeIngredient($ctx['company'], 'g');
    $this->postJson("/api/ingredients/{$g->uuid}/units", ['name' => 'kg', 'factor' => '1000'])
        ->assertStatus(422);

    // base kg -> 'g' likewise. A non-metric custom name still works.
    $kg = makeIngredient($ctx['company'], 'kg');
    $this->postJson("/api/ingredients/{$kg->uuid}/units", ['name' => 'g', 'factor' => '0.001'])
        ->assertStatus(422);
    $this->postJson("/api/ingredients/{$kg->uuid}/units", ['name' => 'scoop', 'factor' => '0.25'])
        ->assertCreated();
});

it('surfaces auto_units on the ingredient resource (PD4)', function (): void {
    $ctx = makeMerchantActor();
    $kg = makeIngredient($ctx['company'], 'kg');
    $piece = makeIngredient($ctx['company'], 'piece');

    $rows = collect($this->getJson('/api/ingredients')->assertOk()->json('data'))->keyBy('uuid');

    $auto = collect($rows[$kg->uuid]['auto_units']);
    expect($auto->pluck('name')->all())->toBe(['g'])
        ->and($auto->firstWhere('name', 'g')['factor'])->toBe('0.001');

    // Count units get no auto siblings.
    expect($rows[$piece->uuid]['auto_units'])->toBe([]);
});
