<?php

declare(strict_types=1);

/**
 * Feature tests for the company-level Taxes CRUD (TaxesController + the 3
 * Actions). Mirrors the DeliveryProviders test conventions.
 *
 * Covers: tenant-scoped list, create + audit, duplicate-name 422, rate
 * validation, diff-aware update + no-op skip, cross-tenant 404, soft delete +
 * audit, and the CatalogueView/Manage permission split.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists company taxes tenant-scoped in sort order', function (): void {
    $ctx = makeMerchantActor();
    Tax::factory()->for($ctx['company'], 'company')->create(['name' => 'VAT', 'rate_percent' => '5.00', 'sort_order' => 1]);
    Tax::factory()->for($ctx['company'], 'company')->create(['name' => 'Municipality', 'rate_percent' => '2.00', 'sort_order' => 2]);

    // Foreign tenant — MUST NOT leak.
    $other = Company::factory()->create();
    Tax::factory()->for($other, 'company')->create(['name' => 'Foreign VAT']);

    $data = $this->getJson('/api/taxes')->assertOk()->json('data');
    expect($data)->toHaveCount(2);
    expect(collect($data)->pluck('name')->all())->toBe(['VAT', 'Municipality']);
});

it('creates a tax and writes the audit row', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->postJson('/api/taxes', [
        'name' => 'VAT',
        'rate_percent' => 5,
    ])->assertCreated();

    expect($res->json('data.name'))->toBe('VAT');
    expect($res->json('data.rate_percent'))->toBe('5.00');
    expect($res->json('data.is_active'))->toBeTrue();

    $row = Tax::query()->where('company_id', $ctx['company']->id)->where('name', 'VAT')->firstOrFail();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'settings.tax.created',
        'auditable_id' => $row->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('returns 422 on a duplicate tax name within the same tenant', function (): void {
    $ctx = makeMerchantActor();
    Tax::factory()->for($ctx['company'], 'company')->create(['name' => 'VAT']);

    $res = $this->postJson('/api/taxes', ['name' => 'VAT', 'rate_percent' => 5])->assertStatus(422);
    expect($res->json('message'))->toContain('already exists');
});

it('validates the rate (required, 0..100)', function (): void {
    makeMerchantActor();

    $this->postJson('/api/taxes', ['name' => 'A'])
        ->assertStatus(422)->assertJsonValidationErrors(['rate_percent']);
    $this->postJson('/api/taxes', ['name' => 'B', 'rate_percent' => 150])
        ->assertStatus(422)->assertJsonValidationErrors(['rate_percent']);
    $this->postJson('/api/taxes', ['name' => 'C', 'rate_percent' => -1])
        ->assertStatus(422)->assertJsonValidationErrors(['rate_percent']);
});

it('updates a tax rate with diff-aware audit', function (): void {
    $ctx = makeMerchantActor();
    $t = Tax::factory()->for($ctx['company'], 'company')->create(['name' => 'VAT', 'rate_percent' => '5.00']);

    $this->patchJson("/api/taxes/{$t->uuid}", ['rate_percent' => 7.5])
        ->assertOk()->assertJsonPath('data.rate_percent', '7.50');

    $t->refresh();
    expect((string) $t->rate_percent)->toBe('7.50');
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'settings.tax.updated',
        'auditable_id' => $t->id,
    ]);
});

it('writes no audit row on a no-op update', function (): void {
    $ctx = makeMerchantActor();
    $t = Tax::factory()->for($ctx['company'], 'company')->create(['name' => 'VAT', 'rate_percent' => '5.00']);

    $this->patchJson("/api/taxes/{$t->uuid}", ['name' => 'VAT', 'rate_percent' => 5])->assertOk();

    $count = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'settings.tax.updated')
        ->where('auditable_id', $t->id)
        ->count();
    expect($count)->toBe(0);
});

it('returns 404 when updating a foreign-tenant tax', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Tax::factory()->for($other, 'company')->create();

    $this->patchJson("/api/taxes/{$foreign->uuid}", ['rate_percent' => 9])->assertNotFound();
});

it('soft-deletes a tax and writes the audit row', function (): void {
    $ctx = makeMerchantActor();
    $t = Tax::factory()->for($ctx['company'], 'company')->create();
    $id = $t->id;

    $this->deleteJson("/api/taxes/{$t->uuid}")->assertNoContent();

    expect(Tax::query()->find($id))->toBeNull();
    expect(Tax::withTrashed()->find($id))->not->toBeNull();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'settings.tax.deleted',
        'auditable_id' => $id,
    ]);
});

it('lets a Viewer read taxes but forbids every write', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $t = Tax::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/taxes')->assertOk();
    $this->postJson('/api/taxes', ['name' => 'X', 'rate_percent' => 5])->assertForbidden();
    $this->patchJson("/api/taxes/{$t->uuid}", ['rate_percent' => 9])->assertForbidden();
    $this->deleteJson("/api/taxes/{$t->uuid}")->assertForbidden();
});
