<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 6 ProductsController.
 *
 * Covers:
 *   - LIST: scoped to actor's company, eager-loads category.
 *   - LIST with ?category=uuid: filters to that category only.
 *     Cross-tenant or bogus uuid yields zero results (no leak).
 *   - CREATE: persists, mints uuid, audit row, money columns
 *     stored as decimal strings.
 *   - CREATE rejects: unknown category, cross-tenant category,
 *     duplicate sku, duplicate barcode, missing price.
 *   - UPDATE: edits, sku self-exclusion, base_price diff audit,
 *     cross-tenant 404, cross-tenant category move → 422.
 *   - DELETE: soft delete + audit (price snapshot captured),
 *     cross-tenant 404.
 *   - Permission gates: CatalogueView for read, CatalogueManage
 *     for write. Viewer can read, CashierSupervisor can't write.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== LIST ===================

it('lists products of the actor\'s company with eager-loaded category', function (): void {
    $ctx = makeMerchantActor();

    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Drinks',
    ]);
    Product::factory()
        ->count(2)
        ->for($ctx['company'], 'company')
        ->for($category, 'category')
        ->create();

    // Foreign tenant data MUST NOT leak.
    $otherCompany = Company::factory()->create();
    $foreignCat = ProductCategory::factory()->for($otherCompany, 'company')->create();
    Product::factory()->for($otherCompany, 'company')->for($foreignCat, 'category')->create();

    $response = $this->getJson('/api/products')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.category.name'))->toBe('Drinks');
});

it('filters products by ?category=uuid', function (): void {
    $ctx = makeMerchantActor();

    $drinks = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    $mains = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Mains']);

    Product::factory()->count(2)->for($ctx['company'], 'company')->for($drinks, 'category')->create();
    Product::factory()->count(3)->for($ctx['company'], 'company')->for($mains, 'category')->create();

    $response = $this->getJson("/api/products?category={$drinks->uuid}")->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    foreach ($response->json('data') as $row) {
        expect($row['category']['name'])->toBe('Drinks');
    }
});

it('returns zero products when ?category= points at a foreign-tenant category', function (): void {
    $ctx = makeMerchantActor();

    // Our products exist.
    $mine = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    Product::factory()->for($ctx['company'], 'company')->for($mine, 'category')->create();

    // Foreign category — uuid leaks through the URL but the
    // -1 sentinel guarantees zero results.
    $otherCompany = Company::factory()->create();
    $foreignCat = ProductCategory::factory()->for($otherCompany, 'company')->create();

    $response = $this->getJson("/api/products?category={$foreignCat->uuid}")->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('returns zero products when ?category= is a bogus uuid', function (): void {
    $ctx = makeMerchantActor();
    $mine = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    Product::factory()->for($ctx['company'], 'company')->for($mine, 'category')->create();

    $response = $this->getJson('/api/products?category=00000000-0000-0000-0000-000000000000')
        ->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

// =================== CREATE ===================

it('creates a product and writes an audit row with the base_price', function (): void {
    $ctx = makeMerchantActor();
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson('/api/products', [
        'name' => 'Espresso',
        'name_ar' => 'إسبريسو',
        'category_id' => $category->id,
        'sku' => 'ESP-01',
        'base_price' => '1.250',
        'tax_rate' => '5.00',
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Espresso');
    expect($response->json('data.sku'))->toBe('ESP-01');
    // Decimal cast → string serialization preserves 3 decimals.
    expect($response->json('data.base_price'))->toBe('1.250');
    expect($response->json('data.tax_rate'))->toBe('5.00');

    $product = Product::query()
        ->where('company_id', $ctx['company']->id)
        ->where('sku', 'ESP-01')
        ->firstOrFail();
    expect((string) $product->base_price)->toBe('1.250');

    // Audit row exists; price IS in the payload (vs PIN/QR
    // which we keep out).
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.product.created',
        'auditable_id' => $product->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('creates a product without a category (uncategorized is valid)', function (): void {
    makeMerchantActor();

    $this->postJson('/api/products', [
        'name' => 'Misc Item',
        'base_price' => '0.500',
    ])
        ->assertCreated()
        ->assertJsonPath('data.category', null);
});

it('refuses to create a product pointing at a foreign-tenant category', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $foreignCat = ProductCategory::factory()->for($otherCompany, 'company')->create();

    $this->postJson('/api/products', [
        'name' => 'Hijack',
        'category_id' => $foreignCat->id,
        'base_price' => '1.000',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

it('refuses a duplicate SKU within the same company', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create(['sku' => 'COKE-330']);

    $this->postJson('/api/products', [
        'name' => 'Coke',
        'sku' => 'COKE-330',
        'base_price' => '0.800',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sku']);
});

it('refuses a duplicate barcode within the same company', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create(['barcode' => '5901234123457']);

    $this->postJson('/api/products', [
        'name' => 'Whatever',
        'barcode' => '5901234123457',
        'base_price' => '1.000',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['barcode']);
});

it('allows the same SKU on two different companies', function (): void {
    // Tenant A registers SKU.
    $ctxA = makeMerchantActor();
    Product::factory()->for($ctxA['company'], 'company')->create(['sku' => 'GLOBAL-1']);

    // Fresh tenant — same SKU is OK because uniqueness is per-company.
    makeMerchantActor();
    $this->postJson('/api/products', [
        'name' => 'Same code, my menu',
        'sku' => 'GLOBAL-1',
        'base_price' => '2.000',
    ])->assertCreated();
});

it('refuses a product missing the base_price', function (): void {
    makeMerchantActor();

    $this->postJson('/api/products', [
        'name' => 'No-price',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['base_price']);
});

// =================== UPDATE ===================

it('edits a product\'s price, name, and tax_rate', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Old Name',
        'base_price' => '2.000',
        'tax_rate' => null,
    ]);

    $this->patchJson("/api/products/{$product->uuid}", [
        'name' => 'New Name',
        'base_price' => '2.500',
        'tax_rate' => '10.00',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.base_price', '2.500')
        ->assertJsonPath('data.tax_rate', '10.00');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.product.updated',
        'auditable_id' => $product->id,
    ]);
});

it('allows PATCH to keep the same SKU (self-exclusion in uniqueness check)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['sku' => 'KEEP-1']);

    $this->patchJson("/api/products/{$product->uuid}", [
        'sku' => 'KEEP-1',
        'name' => 'Edited',
    ])->assertOk();
});

it('refuses to move a product to a foreign-tenant category', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    $otherCompany = Company::factory()->create();
    $foreignCat = ProductCategory::factory()->for($otherCompany, 'company')->create();

    $this->patchJson("/api/products/{$product->uuid}", [
        'category_id' => $foreignCat->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

it('returns 404 when updating a product owned by another company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $foreignProduct = Product::factory()->for($otherCompany, 'company')->create();

    $this->patchJson("/api/products/{$foreignProduct->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

// =================== DELETE ===================

it('soft-deletes a product with an audit row capturing the price-at-delete', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Going Away',
        'base_price' => '3.500',
    ]);
    $productId = $product->id;

    $this->deleteJson("/api/products/{$product->uuid}")
        ->assertNoContent();

    expect(Product::query()->find($productId))->toBeNull();
    expect(Product::withTrashed()->find($productId))->not->toBeNull();

    // Price-at-delete snapshot — Phase 7 order disputes will
    // need this when an order references a deleted product.
    $audit = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.product.deleted')
        ->where('auditable_id', $productId)
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_values)->toContain('3.500');
});

it('returns 404 when deleting a product owned by another company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $foreignProduct = Product::factory()->for($otherCompany, 'company')->create();

    $this->deleteJson("/api/products/{$foreignProduct->uuid}")
        ->assertNotFound();
});

// =================== PERMISSION GATES ===================

it('lets a Viewer list products but forbids creating one', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/products')->assertOk();

    $this->postJson('/api/products', [
        'name' => 'Sneaky',
        'base_price' => '1.000',
    ])->assertForbidden();
});

it('forbids a CashierSupervisor from mutating products', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/products', [
        'name' => 'X',
        'base_price' => '1.000',
    ])->assertForbidden();
    $this->patchJson("/api/products/{$product->uuid}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/products/{$product->uuid}")->assertForbidden();
});

it('lets an InventoryManager create + edit + delete products', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);

    $response = $this->postJson('/api/products', [
        'name' => 'Burger',
        'base_price' => '3.500',
    ])->assertCreated();

    $uuid = $response->json('data.uuid');
    $this->patchJson("/api/products/{$uuid}", ['base_price' => '4.000'])->assertOk();
    $this->deleteJson("/api/products/{$uuid}")->assertNoContent();
});
