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
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
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

it('refuses to change the unit of a recipe-referenced ingredient even with no stock', function (): void {
    $ctx = makeMerchantActor();
    // No stock, no movements — the old guard would have ALLOWED this flip, but a
    // recipe line stores 0.250 in the CURRENT base unit (kg); flipping to g would
    // make the next sale deduct 0.250 g instead of 250 g (1000x under-deduction).
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'kg']);
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    ProductRecipe::factory()
        ->for($product, 'product')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '0.250']);

    $response = $this->patchJson("/api/ingredients/{$ingredient->uuid}", ['unit' => 'g'])
        ->assertStatus(422);
    expect($response->json('message'))->toContain('recipe');
});

it('refuses to change the unit of an ingredient referenced by a legacy add-on', function (): void {
    $ctx = makeMerchantActor();
    // Legacy single-ingredient add-on (pos_addons.ingredient_id/ingredient_qty)
    // — still read by the sale-time deduction pipeline; no stock/movement/recipe.
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'kg']);
    \App\Models\AddOn::factory()->create(['ingredient_id' => $ingredient->id, 'ingredient_qty' => '1.000']);

    $response = $this->patchJson("/api/ingredients/{$ingredient->uuid}", ['unit' => 'g'])
        ->assertStatus(422);
    expect($response->json('message'))->toContain('add-on');
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

// Phase 5b — ingredient can't be deleted while any product
// recipe still references it. The merchant must edit those
// recipes first. Without this guard the Phase 8 sale-
// consumption pipeline would try to deduct stock from a
// soft-deleted ingredient and silently fail.
it('refuses to delete an ingredient that is referenced by a product recipe', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    ProductRecipe::factory()
        ->for($product, 'product')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '0.250']);

    $response = $this->deleteJson("/api/ingredients/{$ingredient->uuid}")
        ->assertStatus(422);
    expect($response->json('message'))->toContain('recipe');

    // Ingredient still alive — the recipe line still references it.
    expect(Ingredient::query()->find($ingredient->id))->not->toBeNull();
});

// Phase 5c — open restock requests block deletion the same
// way recipe references do. The merchant must cancel or fulfil
// the requests first so a soft-deleted ingredient can't leave
// a flight-in-progress allocation pointing at a ghost. Terminal-
// state requests (fulfilled / rejected / cancelled) are
// historical and OK to retain a stale reference — the line
// snapshot preserves the data and the UI tolerates a missing
// ingredient relation.
it('refuses to delete an ingredient referenced by an open restock request', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Test each open state — a Draft / Submitted / Approved
    // request with a line on this ingredient must each block
    // deletion individually.
    foreach (['draft', 'submitted', 'approved'] as $status) {
        $factory = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch');
        $req = match ($status) {
            'draft' => $factory->create(),
            'submitted' => $factory->submitted()->create(),
            'approved' => $factory->approved()->create(),
        };
        RestockRequestLine::factory()->for($req, 'request')->for($ingredient, 'ingredient')->create();

        $response = $this->deleteJson("/api/ingredients/{$ingredient->uuid}")
            ->assertStatus(422);
        expect($response->json('message'))->toContain('restock request');

        // Ingredient still alive (the action threw before the
        // soft-delete touched the row).
        expect(Ingredient::query()->find($ingredient->id))->not->toBeNull();

        // Clean up for the next iteration so each status starts
        // from a known state with no lingering open lines.
        $req->lines()->delete();
        $req->delete();
    }
});

it('allows deletion when only terminal-state restock requests reference the ingredient', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Three terminal-state requests, each with a line on this
    // ingredient. None of them should block the deletion — the
    // snapshot preserves enough data for a historical view to
    // render correctly even after the ingredient is gone.
    $fulfilled = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->fulfilled()->create();
    RestockRequestLine::factory()->for($fulfilled, 'request')->for($ingredient, 'ingredient')->create();
    $rejected = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->rejected()->create();
    RestockRequestLine::factory()->for($rejected, 'request')->for($ingredient, 'ingredient')->create();
    $cancelled = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->cancelled()->create();
    RestockRequestLine::factory()->for($cancelled, 'request')->for($ingredient, 'ingredient')->create();

    $ingredientId = $ingredient->id;
    $this->deleteJson("/api/ingredients/{$ingredient->uuid}")->assertNoContent();

    // Soft-deleted (gone from default query but withTrashed
    // finds it). The terminal-state lines still point at it
    // — that's by design.
    expect(Ingredient::query()->find($ingredientId))->toBeNull();
    expect(Ingredient::withTrashed()->find($ingredientId))->not->toBeNull();
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
