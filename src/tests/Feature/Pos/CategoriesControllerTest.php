<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 6 CategoriesController.
 *
 * Covers:
 *   - LIST: scoped to actor's company, includes products_count.
 *   - CREATE: persists, mints uuid, writes audit row, dupe (company_id,
 *     name) → 422.
 *   - UPDATE: edits, name uniqueness excludes self, cross-tenant 404.
 *   - DELETE: refused when has products, allowed when empty,
 *     soft-delete + audit, cross-tenant 404.
 *   - Permission gates: CatalogueView for read, CatalogueManage for
 *     write. Viewer can read but can't mutate.
 *
 * The catalogue lives under the merchant tenant context — every
 * authenticated request gets its company_id pinned by the
 * SetMerchantTenantContext middleware. makeMerchantActor() does the
 * equivalent setup for tests.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== LIST ===================

it('lists categories of the actor\'s company with products_count', function (): void {
    $ctx = makeMerchantActor();

    $catA = ProductCategory::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Drinks',
        'display_order' => 1,
    ]);
    $catB = ProductCategory::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Mains',
        'display_order' => 2,
    ]);

    Product::factory()
        ->count(3)
        ->for($ctx['company'], 'company')
        ->for($catA, 'category')
        ->create();
    Product::factory()
        ->for($ctx['company'], 'company')
        ->for($catB, 'category')
        ->create();

    // A category from another tenant — MUST NOT leak.
    $otherCompany = Company::factory()->create();
    ProductCategory::factory()->for($otherCompany, 'company')->create(['name' => 'Foreign']);

    $response = $this->getJson('/api/categories')->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(2);

    $byName = collect($data)->keyBy('name');
    expect($byName['Drinks']['products_count'])->toBe(3);
    expect($byName['Mains']['products_count'])->toBe(1);
    // Foreign category MUST be absent.
    expect(collect($data)->pluck('name')->all())->not->toContain('Foreign');
});

// =================== CREATE ===================

it('creates a category and writes an audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/categories', [
        'name' => 'Desserts',
        'name_ar' => 'حلويات',
        'description' => 'Sweet stuff',
        'display_order' => 5,
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Desserts');
    expect($response->json('data.name_ar'))->toBe('حلويات');
    expect($response->json('data.status'))->toBe('active');
    expect($response->json('data.uuid'))->toBeString();

    $category = ProductCategory::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Desserts')
        ->firstOrFail();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.category.created',
        'auditable_id' => $category->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('refuses to create a duplicate category name within the same company', function (): void {
    $ctx = makeMerchantActor();

    ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);

    $this->postJson('/api/categories', ['name' => 'Drinks'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('allows the same category name on two different companies', function (): void {
    // Tenant A picks "Drinks".
    $ctxA = makeMerchantActor();
    ProductCategory::factory()->for($ctxA['company'], 'company')->create(['name' => 'Drinks']);

    // Switch to a fresh tenant — "Drinks" is brand new in their
    // scope. Spatie permissions are per-team, so this is a real
    // re-login simulation, not just a tenant switch.
    $ctxB = makeMerchantActor();
    $this->postJson('/api/categories', ['name' => 'Drinks'])
        ->assertCreated();
});

// =================== UPDATE ===================

it('edits a category name, description, and status', function (): void {
    $ctx = makeMerchantActor();

    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Old Name',
    ]);

    $this->patchJson("/api/categories/{$category->uuid}", [
        'name' => 'New Name',
        'description' => 'Edited',
        'status' => 'inactive',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.description', 'Edited')
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.category.updated',
        'auditable_id' => $category->id,
    ]);
});

it('allows updating a category to the same name (self-exclusion in uniqueness check)', function (): void {
    $ctx = makeMerchantActor();
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);

    // PATCH with same name shouldn't trip the unique validator.
    // Touching description forces a row update so we can check the
    // 200 path.
    $this->patchJson("/api/categories/{$category->uuid}", [
        'name' => 'Drinks',
        'description' => 'tiny tweak',
    ])->assertOk();
});

it('returns 404 when updating a category owned by another company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $foreignCategory = ProductCategory::factory()->for($otherCompany, 'company')->create();

    $this->patchJson("/api/categories/{$foreignCategory->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

// =================== DELETE ===================

it('refuses to delete a category that has products', function (): void {
    $ctx = makeMerchantActor();

    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    Product::factory()
        ->for($ctx['company'], 'company')
        ->for($category, 'category')
        ->create();

    $response = $this->deleteJson("/api/categories/{$category->uuid}")
        ->assertStatus(422);
    // Message names the count so the merchant knows what they're
    // up against — 1 product.
    expect($response->json('message'))->toContain('1');
    expect($response->json('message'))->toContain('product');

    expect(ProductCategory::query()->find($category->id))->not->toBeNull();
});

it('soft-deletes an empty category with audit', function (): void {
    $ctx = makeMerchantActor();
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    $categoryId = $category->id;

    $this->deleteJson("/api/categories/{$category->uuid}")
        ->assertNoContent();

    // Default scope hides soft-deleted; withTrashed sees.
    expect(ProductCategory::query()->find($categoryId))->toBeNull();
    expect(ProductCategory::withTrashed()->find($categoryId))->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.category.deleted',
        'auditable_id' => $categoryId,
    ]);
});

it('returns 404 when deleting a category owned by another company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $foreignCategory = ProductCategory::factory()->for($otherCompany, 'company')->create();

    $this->deleteJson("/api/categories/{$foreignCategory->uuid}")
        ->assertNotFound();
});

// =================== PERMISSION GATES ===================

it('lets a Viewer list categories but forbids creating one', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/categories')->assertOk();

    $this->postJson('/api/categories', ['name' => 'Sneaky'])
        ->assertForbidden();
});

it('forbids a CashierSupervisor from mutating categories', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/categories', ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/categories/{$category->uuid}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/categories/{$category->uuid}")->assertForbidden();
});

it('lets an InventoryManager create and edit categories', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);

    $this->postJson('/api/categories', ['name' => 'Pasta'])->assertCreated();

    $category = ProductCategory::query()->where('name', 'Pasta')->firstOrFail();
    $this->patchJson("/api/categories/{$category->uuid}", ['name' => 'Pasta & Risotto'])
        ->assertOk();
});

// =================== PHASE D2 — branch availability ===================

it('creates a category limited to selected branches', function (): void {
    $ctx = makeMerchantActor();
    $b1 = Branch::factory()->for($ctx['company'], 'company')->create();
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson('/api/categories', [
        'name' => 'Airport Exclusives',
        'branch_ids' => [$b1->id, $b2->id],
    ])->assertCreated();

    expect($response->json('data.branch_ids'))->toBe([$b1->id, $b2->id]);

    $category = ProductCategory::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Airport Exclusives')
        ->firstOrFail();
    expect($category->branch_availability_json)->toBe([$b1->id, $b2->id]);
});

it('defaults a category without branch_ids to all branches (null)', function (): void {
    makeMerchantActor();

    $this->postJson('/api/categories', ['name' => 'Everywhere'])
        ->assertCreated()
        ->assertJsonPath('data.branch_ids', null);
});

it('rejects category branch_ids that belong to another company', function (): void {
    makeMerchantActor();
    $foreignBranch = Branch::factory()->create(); // different company

    $this->postJson('/api/categories', [
        'name' => 'Sneaky',
        'branch_ids' => [$foreignBranch->id],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_ids']);
});

it('updates category branch availability and clears it back to all branches', function (): void {
    $ctx = makeMerchantActor();
    $b1 = Branch::factory()->for($ctx['company'], 'company')->create();
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create([
        'branch_availability_json' => [$b1->id],
    ]);

    // Move the category to branch 2 only.
    $this->patchJson("/api/categories/{$category->uuid}", [
        'branch_ids' => [$b2->id],
    ])
        ->assertOk()
        ->assertJsonPath('data.branch_ids', [$b2->id]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.category.updated',
        'auditable_id' => $category->id,
    ]);

    // Empty selection = back to "all branches" (stored as NULL).
    $this->patchJson("/api/categories/{$category->uuid}", [
        'branch_ids' => [],
    ])
        ->assertOk()
        ->assertJsonPath('data.branch_ids', null);

    expect($category->fresh()->branch_availability_json)->toBeNull();
});

it('rejects updating category branch_ids to a foreign-tenant branch', function (): void {
    $ctx = makeMerchantActor();
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    $foreignBranch = Branch::factory()->create(); // different company

    $this->patchJson("/api/categories/{$category->uuid}", [
        'branch_ids' => [$foreignBranch->id],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_ids']);
});
