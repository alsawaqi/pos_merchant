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

// =================== PAGINATION + SEARCH (v2 #12) ===================

it('server-paginates the catalogue with a {data, meta} envelope', function (): void {
    $ctx = makeMerchantActor();
    $cat = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    Product::factory()->count(60)->for($ctx['company'], 'company')->for($cat, 'category')->create();

    $first = $this->getJson('/api/products?per_page=50')->assertOk();
    expect($first->json('data'))->toHaveCount(50);
    expect($first->json('meta.total'))->toBe(60);
    expect($first->json('meta.last_page'))->toBe(2);
    expect($first->json('meta.current_page'))->toBe(1);

    $second = $this->getJson('/api/products?per_page=50&page=2')->assertOk();
    expect($second->json('data'))->toHaveCount(10);
    expect($second->json('meta.current_page'))->toBe(2);
});

it('filters the catalogue by a case-insensitive name search', function (): void {
    $ctx = makeMerchantActor();
    $cat = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    Product::factory()->for($ctx['company'], 'company')->for($cat, 'category')->create(['name' => 'Iced Latte']);
    Product::factory()->for($ctx['company'], 'company')->for($cat, 'category')->create(['name' => 'Mocha']);
    Product::factory()->for($ctx['company'], 'company')->for($cat, 'category')->create(['name' => 'Tea']);

    $res = $this->getJson('/api/products?search=lat')->assertOk();
    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.name'))->toBe('Iced Latte');
    expect($res->json('meta.total'))->toBe(1);
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

// =================== PHASE 4.9 — DELIVERY_PRICE ===================
//
// delivery_price is a nullable per-product override. NULL means
// "use base_price for delivery orders too"; non-null is the
// markup. Product::priceFor(orderType) is the resolver POS hits
// at order-create time.

it('creates a product with delivery_price stored at decimal:3 precision', function (): void {
    makeMerchantActor();

    $response = $this->postJson('/api/products', [
        'name' => 'Latte',
        'base_price' => '1.500',
        'delivery_price' => '2.000',
    ])->assertCreated();

    expect($response->json('data.base_price'))->toBe('1.500');
    expect($response->json('data.delivery_price'))->toBe('2.000');
});

it('returns delivery_price as null when not set on create', function (): void {
    makeMerchantActor();

    $this->postJson('/api/products', [
        'name' => 'Espresso',
        'base_price' => '0.800',
    ])
        ->assertCreated()
        ->assertJsonPath('data.delivery_price', null);
});

it('updates delivery_price and writes a diff-aware audit', function (): void {
    $ctx = makeMerchantActor();
    $product = \App\Models\Product::factory()->for($ctx['company'], 'company')
        ->create(['base_price' => '2.000', 'delivery_price' => null]);

    $this->patchJson("/api/products/{$product->uuid}", [
        'delivery_price' => '2.500',
    ])
        ->assertOk()
        ->assertJsonPath('data.delivery_price', '2.500');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.product.updated',
        'auditable_id' => $product->id,
    ]);
});

it('clears delivery_price when PATCH sends null', function (): void {
    $ctx = makeMerchantActor();
    $product = \App\Models\Product::factory()->for($ctx['company'], 'company')
        ->create(['base_price' => '2.000', 'delivery_price' => '2.500']);

    $this->patchJson("/api/products/{$product->uuid}", [
        'delivery_price' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.delivery_price', null);

    $product->refresh();
    expect($product->delivery_price)->toBeNull();
});

it('rejects negative or out-of-range delivery_price', function (): void {
    makeMerchantActor();

    $this->postJson('/api/products', [
        'name' => 'Bad',
        'base_price' => '1.000',
        'delivery_price' => '-1.000',
    ])->assertStatus(422)->assertJsonValidationErrors(['delivery_price']);

    $this->postJson('/api/products', [
        'name' => 'TooBig',
        'base_price' => '1.000',
        'delivery_price' => '9999999.999',
    ])->assertStatus(422)->assertJsonValidationErrors(['delivery_price']);
});

// =================== Product::priceFor() unit-level coverage ===================

it('priceFor returns delivery_price when order_type is delivery and delivery_price is set', function (): void {
    $ctx = makeMerchantActor();
    $product = \App\Models\Product::factory()->for($ctx['company'], 'company')
        ->create(['base_price' => '2.000', 'delivery_price' => '2.500']);

    expect($product->priceFor('delivery'))->toBe('2.500');
});

it('priceFor falls back to base_price when delivery_price is null', function (): void {
    $ctx = makeMerchantActor();
    $product = \App\Models\Product::factory()->for($ctx['company'], 'company')
        ->create(['base_price' => '2.000', 'delivery_price' => null]);

    expect($product->priceFor('delivery'))->toBe('2.000');
});

it('priceFor always returns base_price for non-delivery order types', function (): void {
    $ctx = makeMerchantActor();
    $product = \App\Models\Product::factory()->for($ctx['company'], 'company')
        ->create(['base_price' => '2.000', 'delivery_price' => '5.000']);

    expect($product->priceFor('dine_in'))->toBe('2.000');
    expect($product->priceFor('quick_order'))->toBe('2.000');
    expect($product->priceFor('to_go'))->toBe('2.000');
});

// =================== Add-on groups eager-loaded on edit ===================

it('eager-loads addon_groups on product list so the picker can pre-populate', function (): void {
    $ctx = makeMerchantActor();
    $product = \App\Models\Product::factory()->for($ctx['company'], 'company')->create();
    $group = \App\Models\AddOnGroup::factory()->for($ctx['company'], 'company')->create();
    $product->addOnGroups()->attach($group->id);

    $response = $this->getJson('/api/products')->assertOk();

    $rows = $response->json('data');
    $found = collect($rows)->firstWhere('uuid', $product->uuid);
    expect($found)->not->toBeNull();
    expect($found['addon_groups'])->toHaveCount(1);
    expect($found['addon_groups'][0]['uuid'])->toBe($group->uuid);
});

// =================== PHASE D2 — catalogue flags ===================

it('persists stock_mode and the Phase D2 flags on create', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/products', [
        'name' => 'Cheesecake Slice',
        'base_price' => '2.500',
        'stock_mode' => 'unit',
        'low_stock_threshold' => '5',
        'tax_inclusive' => true,
        'show_on_customer_tablet' => false,
    ])->assertCreated();

    expect($response->json('data.stock_mode'))->toBe('unit');
    expect($response->json('data.low_stock_threshold'))->toBe('5.000');
    expect($response->json('data.tax_inclusive'))->toBeTrue();
    expect($response->json('data.show_on_customer_tablet'))->toBeFalse();

    $product = Product::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Cheesecake Slice')
        ->firstOrFail();
    expect($product->stock_mode)->toBe('unit');
    expect((string) $product->low_stock_threshold)->toBe('5.000');
    expect($product->tax_inclusive)->toBeTrue();
    expect($product->show_on_customer_tablet)->toBeFalse();
});

it('defaults the Phase D2 flags on a minimal create', function (): void {
    makeMerchantActor();

    $response = $this->postJson('/api/products', [
        'name' => 'Plain Tea',
        'base_price' => '0.500',
    ])->assertCreated();

    // Blueprint defaults: visible on the tablet, tax-exclusive,
    // no low-stock badge, untracked stock.
    expect($response->json('data.stock_mode'))->toBe('untracked');
    expect($response->json('data.low_stock_threshold'))->toBeNull();
    expect($response->json('data.tax_inclusive'))->toBeFalse();
    expect($response->json('data.show_on_customer_tablet'))->toBeTrue();
});

it('updates stock_mode and the Phase D2 flags', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create([
        'stock_mode' => 'untracked',
    ]);

    $this->patchJson("/api/products/{$product->uuid}", [
        'stock_mode' => 'unit',
        'low_stock_threshold' => '3',
        'tax_inclusive' => true,
        'show_on_customer_tablet' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.stock_mode', 'unit')
        ->assertJsonPath('data.low_stock_threshold', '3.000')
        ->assertJsonPath('data.tax_inclusive', true)
        ->assertJsonPath('data.show_on_customer_tablet', false);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.product.updated',
        'auditable_id' => $product->id,
    ]);

    // Clearing the threshold turns the badge off again.
    $this->patchJson("/api/products/{$product->uuid}", [
        'low_stock_threshold' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.low_stock_threshold', null);
});

it('rejects a negative threshold or a non-boolean Phase D2 flag', function (): void {
    makeMerchantActor();

    $this->postJson('/api/products', [
        'name' => 'Bad threshold',
        'base_price' => '1.000',
        'low_stock_threshold' => '-1',
    ])->assertStatus(422)->assertJsonValidationErrors(['low_stock_threshold']);

    $this->postJson('/api/products', [
        'name' => 'Bad flag',
        'base_price' => '1.000',
        'tax_inclusive' => 'maybe',
    ])->assertStatus(422)->assertJsonValidationErrors(['tax_inclusive']);
});

// =================== G1 — menu time-window ===================

it('persists the availability window on create and echoes the raw strings', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/products', [
        'name' => 'Breakfast Shakshuka',
        'base_price' => '2.200',
        'available_from' => '06:00:00',
        'available_until' => '11:00:00',
    ])->assertCreated();

    expect($response->json('data.available_from'))->toBe('06:00:00');
    expect($response->json('data.available_until'))->toBe('11:00:00');

    $product = Product::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Breakfast Shakshuka')
        ->firstOrFail();
    expect($product->available_from)->toBe('06:00:00');
    expect($product->available_until)->toBe('11:00:00');
});

it('defaults the availability window to null (always available)', function (): void {
    makeMerchantActor();

    $response = $this->postJson('/api/products', [
        'name' => 'All-day Americano',
        'base_price' => '1.200',
    ])->assertCreated();

    expect($response->json('data.available_from'))->toBeNull();
    expect($response->json('data.available_until'))->toBeNull();
});

it('updates the availability window and clears it back to always-available', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    // Set a midnight-wrapping window (overnight menu) — the
    // pos_discounts convention: start > end wraps past 00:00.
    $this->patchJson("/api/products/{$product->uuid}", [
        'available_from' => '22:00:00',
        'available_until' => '02:00:00',
    ])
        ->assertOk()
        ->assertJsonPath('data.available_from', '22:00:00')
        ->assertJsonPath('data.available_until', '02:00:00');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.product.updated',
        'auditable_id' => $product->id,
    ]);

    // Clearing both bounds = always available again.
    $this->patchJson("/api/products/{$product->uuid}", [
        'available_from' => null,
        'available_until' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.available_from', null)
        ->assertJsonPath('data.available_until', null);
});

it('rejects a malformed availability window', function (): void {
    makeMerchantActor();

    $this->postJson('/api/products', [
        'name' => 'Bad window',
        'base_price' => '1.000',
        'available_from' => '25:99',
    ])->assertStatus(422)->assertJsonValidationErrors(['available_from']);

    $this->postJson('/api/products', [
        'name' => 'Bad window too',
        'base_price' => '1.000',
        'available_until' => 'noonish',
    ])->assertStatus(422)->assertJsonValidationErrors(['available_until']);
});
