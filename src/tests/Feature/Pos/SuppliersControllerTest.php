<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 5a SuppliersController.
 *
 * Covers:
 *   - LIST: scoped to actor's company with ingredients_count.
 *   - Cross-tenant isolation on read.
 *   - CRUD happy paths + dupe-name 422 + cross-tenant 404.
 *   - Delete refused when any active ingredient names this
 *     supplier as primary.
 *   - Permission gates.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists suppliers of the actor\'s company with ingredients_count', function (): void {
    $ctx = makeMerchantActor();
    $sup = Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'Acme']);
    Ingredient::factory()->count(3)->for($ctx['company'], 'company')->create(['primary_supplier_id' => $sup->id]);

    // Foreign supplier — must not leak.
    $otherCompany = Company::factory()->create();
    Supplier::factory()->for($otherCompany, 'company')->create(['name' => 'Foreign Sup']);

    $response = $this->getJson('/api/suppliers')->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Acme');
    expect($data[0]['ingredients_count'])->toBe(3);
});

it('creates a supplier and writes an audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/suppliers', [
        'name' => 'Oman Dairy',
        'contact' => '+968 99 999 999',
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Oman Dairy');

    $supplier = Supplier::query()->where('name', 'Oman Dairy')->firstOrFail();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.supplier.created',
        'auditable_id' => $supplier->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('refuses to create a duplicate supplier name within the same company', function (): void {
    $ctx = makeMerchantActor();
    Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'Acme']);

    $this->postJson('/api/suppliers', ['name' => 'Acme'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('edits a supplier and writes a diff audit', function (): void {
    $ctx = makeMerchantActor();
    $sup = Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'Old']);

    $this->patchJson("/api/suppliers/{$sup->uuid}", ['name' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.supplier.updated',
        'auditable_id' => $sup->id,
    ]);
});

it('returns 404 when mutating a supplier owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignSup = Supplier::factory()->for($otherCompany, 'company')->create();

    $this->patchJson("/api/suppliers/{$foreignSup->uuid}", ['name' => 'Hijack'])->assertNotFound();
    $this->deleteJson("/api/suppliers/{$foreignSup->uuid}")->assertNotFound();
});

it('refuses to delete a supplier while any ingredient names it as primary', function (): void {
    $ctx = makeMerchantActor();
    $sup = Supplier::factory()->for($ctx['company'], 'company')->create();
    Ingredient::factory()->for($ctx['company'], 'company')->create(['primary_supplier_id' => $sup->id]);

    $response = $this->deleteJson("/api/suppliers/{$sup->uuid}")->assertStatus(422);
    expect($response->json('message'))->toContain('ingredient');

    expect(Supplier::query()->find($sup->id))->not->toBeNull();
});

it('soft-deletes an unreferenced supplier with audit', function (): void {
    $ctx = makeMerchantActor();
    $sup = Supplier::factory()->for($ctx['company'], 'company')->create();
    $supId = $sup->id;

    $this->deleteJson("/api/suppliers/{$sup->uuid}")->assertNoContent();

    expect(Supplier::query()->find($supId))->toBeNull();
    expect(Supplier::withTrashed()->find($supId))->not->toBeNull();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.supplier.deleted',
        'auditable_id' => $supId,
    ]);
});

it('forbids supplier CRUD to a CashierSupervisor', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $sup = Supplier::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/suppliers', ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/suppliers/{$sup->uuid}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/suppliers/{$sup->uuid}")->assertForbidden();
});
