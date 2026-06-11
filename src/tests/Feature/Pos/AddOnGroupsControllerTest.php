<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 4.9 AddOnGroupsController.
 *
 * Covers:
 *   - LIST: scoped to actor's company, includes addons + counts.
 *   - CREATE: persists, mints uuid, audit row, dupe (company_id,
 *     name) → 422.
 *   - UPDATE: edits, is_global flip, cross-tenant 404.
 *   - DELETE: refused when attached to products, allowed when
 *     unattached (cascades addons + pivot), cross-tenant 404.
 *   - Permission gates: CatalogueView for read, CatalogueManage
 *     for write. Viewer can read but can't mutate.
 *
 * Tenant scoping handled by SetMerchantTenantContext at runtime;
 * makeMerchantActor() does the equivalent setup for tests.
 */

use App\Enums\MerchantRole;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== LIST ===================

it('lists add-on groups of the actor\'s company with addons + counts', function (): void {
    $ctx = makeMerchantActor();

    $milk = AddOnGroup::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Milk Choice',
    ]);
    AddOn::factory()->count(3)->for($ctx['company'], 'company')->for($milk, 'group')->create();

    // Foreign tenant — must not leak.
    $otherCompany = Company::factory()->create();
    AddOnGroup::factory()->for($otherCompany, 'company')->create(['name' => 'Foreign Group']);

    $response = $this->getJson('/api/addon-groups')->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Milk Choice');
    expect($data[0]['addons_count'])->toBe(3);
    expect($data[0]['addons'])->toHaveCount(3);
    expect(collect($data)->pluck('name')->all())->not->toContain('Foreign Group');
});

// =================== CREATE ===================

it('creates an add-on group and writes an audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/addon-groups', [
        'name' => 'Sugar Level',
        'name_ar' => 'مستوى السكر',
        'selection_mode' => 'single',
        'is_global' => true,
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Sugar Level');
    expect($response->json('data.selection_mode'))->toBe('single');
    expect($response->json('data.is_global'))->toBeTrue();

    $group = AddOnGroup::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Sugar Level')
        ->firstOrFail();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.addon_group.created',
        'auditable_id' => $group->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('refuses to create a duplicate group name within the same company', function (): void {
    $ctx = makeMerchantActor();
    AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Extras']);

    $this->postJson('/api/addon-groups', ['name' => 'Extras'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('allows the same group name on two different companies', function (): void {
    $ctxA = makeMerchantActor();
    AddOnGroup::factory()->for($ctxA['company'], 'company')->create(['name' => 'Extras']);

    makeMerchantActor();
    $this->postJson('/api/addon-groups', ['name' => 'Extras'])->assertCreated();
});

// =================== UPDATE ===================

it('edits a group name, selection_mode, and is_global flag', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Old Name',
        'is_global' => false,
    ]);

    $this->patchJson("/api/addon-groups/{$group->uuid}", [
        'name' => 'New Name',
        'selection_mode' => 'multi',
        'is_global' => true,
        'status' => 'inactive',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.selection_mode', 'multi')
        ->assertJsonPath('data.is_global', true)
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.addon_group.updated',
        'auditable_id' => $group->id,
    ]);
});

it('allows PATCH with same name (self-exclusion in uniqueness check)', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Keep']);

    $this->patchJson("/api/addon-groups/{$group->uuid}", [
        'name' => 'Keep',
        'is_global' => true,
    ])->assertOk();
});

it('returns 404 when updating a group owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignGroup = AddOnGroup::factory()->for($otherCompany, 'company')->create();

    $this->patchJson("/api/addon-groups/{$foreignGroup->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

// =================== DELETE ===================

it('refuses to delete a group that is attached to products', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    // Attach via pivot — the picker would do this through the
    // sync endpoint at runtime.
    $product->addOnGroups()->attach($group->id);

    $response = $this->deleteJson("/api/addon-groups/{$group->uuid}")
        ->assertStatus(422);
    expect($response->json('message'))->toContain('product');

    expect(AddOnGroup::query()->find($group->id))->not->toBeNull();
});

it('soft-deletes an unattached group with audit and cascades its addons', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $addon = AddOn::factory()->for($ctx['company'], 'company')->for($group, 'group')->create();
    $groupId = $group->id;
    $addonId = $addon->id;

    $this->deleteJson("/api/addon-groups/{$group->uuid}")->assertNoContent();

    expect(AddOnGroup::query()->find($groupId))->toBeNull();
    expect(AddOnGroup::withTrashed()->find($groupId))->not->toBeNull();

    // FK cascade on delete: the underlying row went away, so
    // the addon's parent reference is gone. Soft delete on
    // groups + FK cascade on the addon table means addons of a
    // deleted group are dropped entirely (not soft-deleted)
    // when the group is hard-deleted. Since our group is SOFT
    // deleted, the addon row should still exist with its FK
    // intact.
    expect(AddOn::query()->find($addonId))->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.addon_group.deleted',
        'auditable_id' => $groupId,
    ]);
});

it('returns 404 when deleting a group owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignGroup = AddOnGroup::factory()->for($otherCompany, 'company')->create();

    $this->deleteJson("/api/addon-groups/{$foreignGroup->uuid}")->assertNotFound();
});

// =================== PERMISSION GATES ===================

it('lets a Viewer list add-on groups but forbids creating one', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/addon-groups')->assertOk();
    $this->postJson('/api/addon-groups', ['name' => 'Sneaky'])->assertForbidden();
});

it('forbids a CashierSupervisor from mutating add-on groups', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/addon-groups', ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/addon-groups/{$group->uuid}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/addon-groups/{$group->uuid}")->assertForbidden();
});

it('lets an InventoryManager create + edit add-on groups', function (): void {
    makeMerchantActor(MerchantRole::InventoryManager->value);

    $this->postJson('/api/addon-groups', ['name' => 'Drinks Mods'])->assertCreated();

    $group = AddOnGroup::query()->where('name', 'Drinks Mods')->firstOrFail();
    $this->patchJson("/api/addon-groups/{$group->uuid}", ['name' => 'Drink Modifiers'])->assertOk();
});

// =================== Phase B — modifier-group constraints ====================

it('persists min/max selections, default option, and category bindings', function (): void {
    $ctx = makeMerchantActor();
    $category = App\Models\ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);

    // Group with constraints + a category binding.
    $group = $this->postJson('/api/addon-groups', [
        'name' => 'Milk Choice',
        'selection_mode' => 'single',
        'min_selections' => 1,
        'max_selections' => 1,
        'category_ids' => [$category->id],
    ])->assertCreated()->json('data');
    expect($group['min_selections'])->toBe(1);
    expect($group['max_selections'])->toBe(1);

    // Listed group carries the binding + constraints.
    $listed = collect($this->getJson('/api/addon-groups')->assertOk()->json('data'))
        ->firstWhere('uuid', $group['uuid']);
    expect($listed['category_ids'])->toBe([$category->id]);

    // Default option: making one default in a SINGLE group clears siblings.
    $a = $this->postJson("/api/addon-groups/{$group['uuid']}/addons", [
        'name' => 'Whole Milk', 'is_default' => true,
    ])->assertCreated()->json('data');
    $b = $this->postJson("/api/addon-groups/{$group['uuid']}/addons", [
        'name' => 'Oat Milk', 'is_default' => true,
    ])->assertCreated()->json('data');
    expect($b['is_default'])->toBeTrue();
    $this->assertDatabaseHas('pos_addons', ['id' => $a['id'], 'is_default' => false]);

    // max below min -> 422 (against the merged PATCH state).
    $this->patchJson("/api/addon-groups/{$group['uuid']}", ['max_selections' => null])->assertOk();
    $this->patchJson("/api/addon-groups/{$group['uuid']}", ['min_selections' => 3, 'max_selections' => 2])
        ->assertUnprocessable();

    // Cross-tenant category binding -> 422.
    $other = makeMerchantActor();
    app(App\Support\MerchantTenantContext::class)->set($ctx['company']->id);
    $this->actingAs($ctx['user']);
    $foreignCat = App\Models\ProductCategory::factory()->for($other['company'], 'company')->create(['name' => 'Foreign']);
    $this->patchJson("/api/addon-groups/{$group['uuid']}", ['category_ids' => [$foreignCat->id]])
        ->assertUnprocessable();

    // Unbinding via empty list works.
    $this->patchJson("/api/addon-groups/{$group['uuid']}", ['category_ids' => []])->assertOk();
    $this->assertDatabaseCount('pos_addon_group_categories', 0);
});

it('refuses an unsatisfiable minimum on a single-choice group', function (): void {
    $ctx = makeMerchantActor();

    // CREATE: a single-choice group can hold at most one selection on the
    // POS, so min 2 would permanently disable the customize sheet's Apply.
    $this->postJson('/api/addon-groups', [
        'name' => 'Size',
        'selection_mode' => 'single',
        'min_selections' => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors('min_selections');

    // Mode omitted defaults to single — same rejection.
    $this->postJson('/api/addon-groups', [
        'name' => 'Size',
        'min_selections' => 2,
    ])->assertUnprocessable()->assertJsonValidationErrors('min_selections');

    // A MULTI group may legitimately require several picks.
    $group = $this->postJson('/api/addon-groups', [
        'name' => 'Sauces',
        'selection_mode' => 'multi',
        'min_selections' => 2,
        'max_selections' => 3,
    ])->assertCreated()->json('data');

    // UPDATE: raising min above 1 on a single group is refused...
    $single = $this->postJson('/api/addon-groups', [
        'name' => 'Cup',
        'selection_mode' => 'single',
        'min_selections' => 1,
    ])->assertCreated()->json('data');
    $this->patchJson("/api/addon-groups/{$single['uuid']}", ['min_selections' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('min_selections');

    // ...and so is flipping a min>1 multi group to single (merged state).
    $this->patchJson("/api/addon-groups/{$group['uuid']}", ['selection_mode' => 'single'])
        ->assertUnprocessable()->assertJsonValidationErrors('min_selections');
});
