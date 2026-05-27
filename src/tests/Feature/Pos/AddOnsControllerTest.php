<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 4.9 AddOnsController + the
 * product↔add-on-group sync endpoint on ProductsController.
 *
 * Covers:
 *   - Create / update / delete addons (option-inside-a-group).
 *   - Cross-tenant: nested group route 404s when the group
 *     belongs to another company; flat addon route 404s when
 *     the addon does.
 *   - Soft-delete preserves historical row + audit captures
 *     the price snapshot.
 *   - SyncProductAddOnGroups: happy path (one audit row),
 *     no-op (zero audit rows), bogus uuid → 422 rollback,
 *     cross-tenant uuid → 422 rollback, cross-tenant product
 *     → 404, global groups silently skipped, empty array
 *     detaches all.
 *   - Permission gates throughout.
 */

use App\Enums\MerchantRole;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== ADDON CRUD ===================

it('creates an addon inside a group and writes an audit row with price_delta', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Extra shot',
        'price_delta' => '0.500',
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Extra shot');
    expect($response->json('data.price_delta'))->toBe('0.500');

    $addon = AddOn::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Extra shot')
        ->firstOrFail();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.addon.created',
        'auditable_id' => $addon->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('refuses to create an addon under a group from another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignGroup = AddOnGroup::factory()->for($otherCompany, 'company')->create();

    // Route-bound model lookup uses uuid + tenant — the
    // controller's refuseIfGroupNotInTenant short-circuits to 404
    // before validation runs.
    $this->postJson("/api/addon-groups/{$foreignGroup->uuid}/addons", [
        'name' => 'Hijack',
        'price_delta' => '1.000',
    ])->assertNotFound();
});

it('edits an addon price_delta and writes a diff audit', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $addon = AddOn::factory()->for($ctx['company'], 'company')->for($group, 'group')
        ->create(['name' => 'Whole milk', 'price_delta' => '0.000']);

    $this->patchJson("/api/addons/{$addon->uuid}", [
        'price_delta' => '0.250',
        'status' => 'inactive',
    ])
        ->assertOk()
        ->assertJsonPath('data.price_delta', '0.250')
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.addon.updated',
        'auditable_id' => $addon->id,
    ]);
});

it('returns 404 when updating an addon owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignGroup = AddOnGroup::factory()->for($otherCompany, 'company')->create();
    $foreignAddon = AddOn::factory()->for($otherCompany, 'company')->for($foreignGroup, 'group')->create();

    $this->patchJson("/api/addons/{$foreignAddon->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

it('soft-deletes an addon with an audit row capturing price-at-delete', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $addon = AddOn::factory()->for($ctx['company'], 'company')->for($group, 'group')
        ->create(['name' => 'Oat milk', 'price_delta' => '0.500']);
    $addonId = $addon->id;

    $this->deleteJson("/api/addons/{$addon->uuid}")->assertNoContent();

    expect(AddOn::query()->find($addonId))->toBeNull();
    expect(AddOn::withTrashed()->find($addonId))->not->toBeNull();

    $audit = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.addon.deleted')
        ->where('auditable_id', $addonId)
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values)->toContain('0.500');
});

// =================== PRODUCT ↔ GROUP SYNC ===================

it('attaches add-on groups to a product and writes ONE audit row', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    $size = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Size']);

    $this->putJson("/api/products/{$product->uuid}/addon-groups", [
        'group_uuids' => [$milk->uuid, $size->uuid],
    ])->assertOk();

    expect($product->addOnGroups()->count())->toBe(2);

    $audits = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.product.addons_synced')
        ->where('auditable_id', $product->id)
        ->count();
    expect($audits)->toBe(1);
});

it('detaches removed groups when the sync set shrinks', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $size = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $product->addOnGroups()->attach([$milk->id, $size->id]);

    // Send only one of the two — the other should detach.
    $this->putJson("/api/products/{$product->uuid}/addon-groups", [
        'group_uuids' => [$milk->uuid],
    ])->assertOk();

    expect($product->addOnGroups()->pluck('pos_addon_groups.id')->all())->toBe([$milk->id]);
});

it('writes ZERO audit rows on a no-op sync (same set)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $product->addOnGroups()->attach($milk->id);

    $this->putJson("/api/products/{$product->uuid}/addon-groups", [
        'group_uuids' => [$milk->uuid],
    ])->assertOk();

    $audits = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.product.addons_synced')
        ->where('auditable_id', $product->id)
        ->count();
    expect($audits)->toBe(0);
});

it('detaches all groups when sync is called with an empty array', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $product->addOnGroups()->attach($group->id);

    $this->putJson("/api/products/{$product->uuid}/addon-groups", [
        'group_uuids' => [],
    ])->assertOk();

    expect($product->addOnGroups()->count())->toBe(0);
});

it('returns 422 if any group uuid in the sync payload is bogus, rolling back', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $real = AddOnGroup::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/addon-groups", [
        'group_uuids' => [$real->uuid, '00000000-0000-0000-0000-000000000000'],
    ])->assertStatus(422);

    // Real group must NOT have been attached (transaction rolled back).
    expect($product->addOnGroups()->count())->toBe(0);
});

it('returns 422 when sync payload includes a group from another company', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $otherCompany = Company::factory()->create();
    $foreignGroup = AddOnGroup::factory()->for($otherCompany, 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/addon-groups", [
        'group_uuids' => [$foreignGroup->uuid],
    ])->assertStatus(422);

    expect($product->addOnGroups()->count())->toBe(0);
});

it('returns 404 when sync targets a product owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignProduct = Product::factory()->for($otherCompany, 'company')->create();

    $this->putJson("/api/products/{$foreignProduct->uuid}/addon-groups", [
        'group_uuids' => [],
    ])->assertNotFound();
});

it('silently skips global groups in the pivot but still 200s', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    // Global group — applies to every product automatically,
    // not via the pivot. Including it in the sync set should
    // be a no-op on the pivot side.
    $global = AddOnGroup::factory()->for($ctx['company'], 'company')->global()->create();

    $this->putJson("/api/products/{$product->uuid}/addon-groups", [
        'group_uuids' => [$global->uuid],
    ])->assertOk();

    expect($product->addOnGroups()->count())->toBe(0);
});

// =================== PERMISSION GATES ===================

it('forbids the sync endpoint to a Viewer', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/addon-groups", ['group_uuids' => []])
        ->assertForbidden();
});

it('forbids addon CRUD to a CashierSupervisor', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $addon = AddOn::factory()->for($ctx['company'], 'company')->for($group, 'group')->create();

    $this->postJson("/api/addon-groups/{$group->uuid}/addons", ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/addons/{$addon->uuid}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/addons/{$addon->uuid}")->assertForbidden();
});
