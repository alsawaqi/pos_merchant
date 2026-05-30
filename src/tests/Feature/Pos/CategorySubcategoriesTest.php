<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Subcategories (§5.5.1) — a two-level category hierarchy via parent_id.
 *
 * Covers create/update re-parenting, the 2-level cap, self-parent + cross-
 * tenant guards, promote-to-top-level, the delete-with-children guard, and the
 * parent_id / subcategories_count projection.
 */

// =================== CREATE ===================

it('creates a subcategory under a top-level parent', function (): void {
    $ctx = makeMerchantActor();
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);

    $res = $this->postJson('/api/categories', ['name' => 'Hot Drinks', 'parent_id' => $parent->id])
        ->assertCreated();

    expect($res->json('data.parent_id'))->toBe($parent->id);
    $this->assertDatabaseHas('pos_product_categories', [
        'name' => 'Hot Drinks', 'company_id' => $ctx['company']->id, 'parent_id' => $parent->id,
    ]);
});

it('rejects nesting more than one level deep on create', function (): void {
    $ctx = makeMerchantActor();
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    $sub = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot Drinks', 'parent_id' => $parent->id]);

    // Trying to nest under a category that is itself a subcategory.
    $this->postJson('/api/categories', ['name' => 'Espresso Drinks', 'parent_id' => $sub->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_id']);
});

it('rejects a parent from another company on create', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreignParent = ProductCategory::factory()->for($other, 'company')->create();

    $this->postJson('/api/categories', ['name' => 'Sneaky', 'parent_id' => $foreignParent->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_id']);
});

// =================== UPDATE / RE-PARENT ===================

it('re-parents a top-level category under another', function (): void {
    $ctx = makeMerchantActor();
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    $loner = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Smoothies']);

    $this->patchJson("/api/categories/{$loner->uuid}", ['parent_id' => $parent->id])
        ->assertOk()
        ->assertJsonPath('data.parent_id', $parent->id);
});

it('promotes a subcategory back to top-level with parent_id null', function (): void {
    $ctx = makeMerchantActor();
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    $sub = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot Drinks', 'parent_id' => $parent->id]);

    $this->patchJson("/api/categories/{$sub->uuid}", ['parent_id' => null])
        ->assertOk()
        ->assertJsonPath('data.parent_id', null);

    expect($sub->fresh()->parent_id)->toBeNull();
});

it('refuses to make a category its own parent', function (): void {
    $ctx = makeMerchantActor();
    $cat = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);

    $this->patchJson("/api/categories/{$cat->uuid}", ['parent_id' => $cat->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_id']);
});

it('refuses to nest a category that itself has subcategories', function (): void {
    $ctx = makeMerchantActor();
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot Drinks', 'parent_id' => $parent->id]);
    $target = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Mains']);

    // Drinks (which has a child) can't become a child of Mains — that's 3 levels.
    $this->patchJson("/api/categories/{$parent->uuid}", ['parent_id' => $target->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['parent_id']);
});

// =================== DELETE ===================

it('refuses to delete a category that still has subcategories', function (): void {
    $ctx = makeMerchantActor();
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot Drinks', 'parent_id' => $parent->id]);

    $this->deleteJson("/api/categories/{$parent->uuid}")
        ->assertStatus(422);

    $this->assertDatabaseHas('pos_product_categories', ['id' => $parent->id, 'deleted_at' => null]);
});

it('allows deleting a subcategory (leaf)', function (): void {
    $ctx = makeMerchantActor();
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    $sub = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot Drinks', 'parent_id' => $parent->id]);

    $this->deleteJson("/api/categories/{$sub->uuid}")->assertNoContent();
});

// =================== PROJECTION ===================

it('exposes parent_id and subcategories_count in the list', function (): void {
    $ctx = makeMerchantActor();
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks', 'display_order' => 1]);
    ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot Drinks', 'parent_id' => $parent->id, 'display_order' => 2]);
    ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Cold Drinks', 'parent_id' => $parent->id, 'display_order' => 3]);

    $data = collect($this->getJson('/api/categories')->assertOk()->json('data'))->keyBy('name');

    expect($data['Drinks']['parent_id'])->toBeNull();
    expect($data['Drinks']['subcategories_count'])->toBe(2);
    expect($data['Hot Drinks']['parent_id'])->toBe($parent->id);
    expect($data['Hot Drinks']['subcategories_count'])->toBe(0);
});

// =================== PERMISSION ===================

it('forbids a read-only role from creating a subcategory', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $parent = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);

    $this->postJson('/api/categories', ['name' => 'Hot Drinks', 'parent_id' => $parent->id])
        ->assertForbidden();
});
