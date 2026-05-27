<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 5a IngredientsController.
 *
 * Covers:
 *   - LIST: scoped to actor's company, eager-loaded supplier.
 *   - Cross-tenant isolation on read.
 *   - CREATE: persists, mints uuid, audit row, dupe (company_id,
 *     name) → 422, cross-tenant supplier → 422.
 *   - UPDATE: edits + diff audit, cross-tenant 404, unit-change
 *     blocked once history exists.
 *   - DELETE: refused when any branch holds non-zero stock,
 *     allowed when empty, cross-tenant 404.
 *   - Permission gates: InventoryView for read, InventoryManage
 *     for write. Viewer read-only, CashierSupervisor blocked
 *     from mutations, InventoryManager full CRUD.
 */

use App\Enums\MerchantRole;
use App\Models\BranchStock;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== LIST ===================

it('lists ingredients of the actor\'s company with supplier eager-loaded', function (): void {
    $ctx = makeMerchantActor();

    $supplier = Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'Acme Foods']);
    Ingredient::factory()
        ->for($ctx['company'], 'company')
        ->create(['name' => 'Whole Milk', 'primary_supplier_id' => $supplier->id]);

    // Foreign tenant data MUST NOT leak.
    $otherCompany = Company::factory()->create();
    Ingredient::factory()->for($otherCompany, 'company')->create(['name' => 'Foreign Ingredient']);

    $response = $this->getJson('/api/ingredients')->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Whole Milk');
    expect($data[0]['primary_supplier']['name'])->toBe('Acme Foods');
});

// =================== CREATE ===================

it('creates an ingredient and writes an audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/ingredients', [
        'name' => 'Espresso Beans',
        'name_ar' => 'حبوب الإسبريسو',
        'unit' => 'kg',
        'default_unit_cost' => '15.500',
        'min_stock_threshold' => '2.000',
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Espresso Beans');
    expect($response->json('data.unit'))->toBe('kg');
    expect($response->json('data.default_unit_cost'))->toBe('15.500');

    $ingredient = Ingredient::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Espresso Beans')
        ->firstOrFail();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.ingredient.created',
        'auditable_id' => $ingredient->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('refuses to create a duplicate ingredient name within the same company', function (): void {
    $ctx = makeMerchantActor();
    Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);

    $this->postJson('/api/ingredients', [
        'name' => 'Milk',
        'unit' => 'l',
    ])->assertStatus(422)->assertJsonValidationErrors(['name']);
});

it('refuses to create an ingredient with a foreign-tenant supplier', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignSupplier = Supplier::factory()->for($otherCompany, 'company')->create();

    $this->postJson('/api/ingredients', [
        'name' => 'Hijack',
        'unit' => 'kg',
        'primary_supplier_id' => $foreignSupplier->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['primary_supplier_id']);
});

// =================== UPDATE ===================

it('edits an ingredient and writes a diff-aware audit', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Old Name', 'default_unit_cost' => '2.000']);

    $this->patchJson("/api/ingredients/{$ingredient->uuid}", [
        'name' => 'New Name',
        'default_unit_cost' => '2.500',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.default_unit_cost', '2.500');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.ingredient.updated',
        'auditable_id' => $ingredient->id,
    ]);
});

it('returns 404 when updating an ingredient owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();

    $this->patchJson("/api/ingredients/{$foreignIngredient->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

it('refuses to change the unit of an ingredient that already has stock or movements', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'kg']);
    // Seed a non-zero balance to simulate history.
    BranchStock::factory()
        ->for($ctx['branch'], 'branch')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '5.000']);

    $response = $this->patchJson("/api/ingredients/{$ingredient->uuid}", ['unit' => 'g'])
        ->assertStatus(422);
    expect($response->json('message'))->toContain('unit');
});

// =================== DELETE ===================

it('refuses to delete an ingredient with non-zero stock at any branch', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    BranchStock::factory()
        ->for($ctx['branch'], 'branch')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '3.000']);

    $response = $this->deleteJson("/api/ingredients/{$ingredient->uuid}")
        ->assertStatus(422);
    expect($response->json('message'))->toContain('stock');

    expect(Ingredient::query()->find($ingredient->id))->not->toBeNull();
});

it('soft-deletes an ingredient with zero stock with audit', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $ingredientId = $ingredient->id;

    $this->deleteJson("/api/ingredients/{$ingredient->uuid}")->assertNoContent();

    expect(Ingredient::query()->find($ingredientId))->toBeNull();
    expect(Ingredient::withTrashed()->find($ingredientId))->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.ingredient.deleted',
        'auditable_id' => $ingredientId,
    ]);
});

it('returns 404 when deleting an ingredient owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();

    $this->deleteJson("/api/ingredients/{$foreignIngredient->uuid}")->assertNotFound();
});

// =================== PERMISSION GATES ===================

it('lets a Viewer list ingredients but forbids creating one', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/ingredients')->assertOk();
    $this->postJson('/api/ingredients', ['name' => 'Sneaky', 'unit' => 'kg'])->assertForbidden();
});

it('forbids a CashierSupervisor from mutating ingredients', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/ingredients', ['name' => 'X', 'unit' => 'kg'])->assertForbidden();
    $this->patchJson("/api/ingredients/{$ingredient->uuid}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/ingredients/{$ingredient->uuid}")->assertForbidden();
});

it('lets an InventoryManager create + edit + delete ingredients', function (): void {
    makeMerchantActor(MerchantRole::InventoryManager->value);

    $this->postJson('/api/ingredients', ['name' => 'Sugar', 'unit' => 'kg'])->assertCreated();
    $ingredient = Ingredient::query()->where('name', 'Sugar')->firstOrFail();
    $this->patchJson("/api/ingredients/{$ingredient->uuid}", ['default_unit_cost' => '1.500'])->assertOk();
    $this->deleteJson("/api/ingredients/{$ingredient->uuid}")->assertNoContent();
});
