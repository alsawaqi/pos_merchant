<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B1 — the Tax settings on the Taxes page (owner decision 1).
 *
 * VAT registration and the VAT number come read-only from the company record;
 * "Menu prices include VAT" is the merchant's own switch (pos_company_settings
 * tax.prices_include_vat, default ON); a registered business with no active
 * tax row is warned and can add "VAT 5%" in one click. Before: none of this
 * existed — the routes 404.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('shows an unregistered business as not registered, with prices including VAT by default', function (): void {
    makeMerchantActor();

    $this->getJson('/api/settings/tax')
        ->assertOk()
        ->assertJsonPath('data.vat_registered', false)
        ->assertJsonPath('data.vat_number', null)
        ->assertJsonPath('data.prices_include_vat', true)
        ->assertJsonPath('data.needs_vat_row', false);
});

it('shows the VAT number from the company record and warns when a registered business charges no tax', function (): void {
    $ctx = makeMerchantActor();
    p4RegisterVat($ctx['company'], 'OM1100223344', '2026-02-01');

    $this->getJson('/api/settings/tax')
        ->assertOk()
        ->assertJsonPath('data.vat_registered', true)
        ->assertJsonPath('data.vat_registered_at', '2026-02-01')
        ->assertJsonPath('data.vat_number', 'OM1100223344')
        ->assertJsonPath('data.has_active_tax', false)
        ->assertJsonPath('data.needs_vat_row', true);

    // An inactive row still charges nothing.
    Tax::factory()->for($ctx['company'], 'company')->create(['name' => 'Municipality', 'rate_percent' => '2.00', 'is_active' => false]);
    $this->getJson('/api/settings/tax')->assertJsonPath('data.needs_vat_row', true);
});

it('adds VAT 5% in one click for a registered business, with the Arabic name, and audits it', function (): void {
    $ctx = makeMerchantActor();
    p4RegisterVat($ctx['company']);

    $this->postJson('/api/settings/tax/add-vat')
        ->assertCreated()
        ->assertJsonPath('data.needs_vat_row', false)
        ->assertJsonPath('data.tax.name', 'VAT')
        ->assertJsonPath('data.tax.rate_percent', '5.00');

    $this->assertDatabaseHas('pos_taxes', [
        'company_id' => $ctx['company']->id,
        'name' => 'VAT',
        'name_ar' => 'ضريبة القيمة المضافة',
        'rate_percent' => '5.00',
        'is_active' => true,
    ]);
    $this->assertDatabaseHas('pos_audit_logs', ['company_id' => $ctx['company']->id, 'event' => 'settings.tax.vat_added']);
});

it('switches a deleted VAT row back on instead of colliding with its name', function (): void {
    $ctx = makeMerchantActor();
    p4RegisterVat($ctx['company']);
    $old = Tax::factory()->for($ctx['company'], 'company')->create(['name' => 'VAT', 'rate_percent' => '7.00', 'is_active' => true]);
    $old->delete();

    $this->postJson('/api/settings/tax/add-vat')->assertCreated();

    $row = Tax::query()->withTrashed()->where('company_id', $ctx['company']->id)->where('name', 'VAT')->sole();
    expect($row->trashed())->toBeFalse()
        ->and((string) $row->rate_percent)->toBe('5.00')
        ->and($row->is_active)->toBeTrue();
});

it('refuses the one-click VAT for an unregistered business or when a tax is already active', function (): void {
    $ctx = makeMerchantActor();

    $this->postJson('/api/settings/tax/add-vat')->assertStatus(422);
    expect(Tax::query()->where('company_id', $ctx['company']->id)->count())->toBe(0);

    p4RegisterVat($ctx['company']);
    Tax::factory()->for($ctx['company'], 'company')->create(['name' => 'VAT', 'rate_percent' => '5.00', 'is_active' => true]);
    $this->postJson('/api/settings/tax/add-vat')->assertStatus(422);
});

it('saves "menu prices include VAT" as a company setting and audits the change', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson('/api/settings/tax/prices-include-vat', ['prices_include_vat' => false])
        ->assertOk()
        ->assertJsonPath('data.prices_include_vat', false);
    $this->getJson('/api/settings/tax')->assertJsonPath('data.prices_include_vat', false);

    $row = DB::table('pos_company_settings')->where('company_id', $ctx['company']->id)->where('key', 'tax.prices_include_vat')->first();
    expect(json_decode((string) $row->value, true))->toBeFalse();
    $this->assertDatabaseHas('pos_audit_logs', ['company_id' => $ctx['company']->id, 'event' => 'settings.tax.prices_include_vat.updated']);

    $this->putJson('/api/settings/tax/prices-include-vat', ['prices_include_vat' => true])
        ->assertOk()
        ->assertJsonPath('data.prices_include_vat', true);
});

it('lets a catalogue viewer read the VAT settings but not change them', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    p4RegisterVat($ctx['company']);

    $this->getJson('/api/settings/tax')->assertOk();
    $this->putJson('/api/settings/tax/prices-include-vat', ['prices_include_vat' => false])->assertForbidden();
    $this->postJson('/api/settings/tax/add-vat')->assertForbidden();
});

it('keeps the settings of one company away from another', function (): void {
    $ctx = makeMerchantActor();
    $other = Company::factory()->create();
    p4RegisterVat($other, 'OM9999999999');
    DB::table('pos_company_settings')->insert(['company_id' => $other->id, 'key' => 'tax.prices_include_vat', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()]);

    $this->getJson('/api/settings/tax')
        ->assertJsonPath('data.vat_registered', false)
        ->assertJsonPath('data.vat_number', null)
        ->assertJsonPath('data.prices_include_vat', true);
    expect($ctx['company']->id)->not->toBe($other->id);
});
