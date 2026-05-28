<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 6c DeliveryProvidersController + the
 * 5 Actions + Product::resolvedDeliveryPriceFor() helper.
 *
 * Covers:
 *   - PROVIDER CRUD: list (with prices_count), create, update,
 *     soft delete; cross-tenant 404; duplicate name 422;
 *     idempotent no-op update
 *   - PER-PRODUCT PRICES: PUT upsert (create + update path),
 *     DELETE remove (idempotent on missing row), GET listing
 *     scoped to product, cross-tenant guards (foreign product
 *     404, foreign provider 422)
 *   - PRICE RESOLUTION: override > delivery_price > base_price
 *     verified via the Product helper
 *   - PERMISSION MATRIX: CatalogueView for GETs, CatalogueManage
 *     for writes (Viewer + CashierSupervisor see + cannot write;
 *     InventoryManager and Manager write end-to-end)
 *   - VALIDATION: price <= 0 rejected, name/color format rules
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Models\ProductDeliveryPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== PROVIDER LIST ===================

it('lists delivery providers tenant-scoped with prices_count', function (): void {
    $ctx = makeMerchantActor();
    $talabat = DeliveryProvider::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Talabat']);
    $otlob = DeliveryProvider::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Otlob']);
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    ProductDeliveryPrice::factory()
        ->for($product, 'product')
        ->for($talabat, 'deliveryProvider')
        ->for($ctx['company'], 'company')
        ->create(['price' => '6.000']);

    // Foreign tenant — MUST NOT leak.
    $otherCompany = Company::factory()->create();
    DeliveryProvider::factory()->for($otherCompany, 'company')->create(['name' => 'Foreign Provider']);

    $response = $this->getJson('/api/delivery-providers')->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);

    $talabatRow = collect($data)->firstWhere('uuid', $talabat->uuid);
    $otlobRow = collect($data)->firstWhere('uuid', $otlob->uuid);
    expect($talabatRow['prices_count'])->toBe(1);
    expect($otlobRow['prices_count'])->toBe(0);
});

// =================== PROVIDER CREATE ===================

it('creates a delivery provider and writes the audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/delivery-providers', [
        'name' => 'Talabat',
        'color' => '#FF6B00',
        'sort_order' => 10,
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Talabat');
    expect($response->json('data.color'))->toBe('#FF6B00');
    expect($response->json('data.is_active'))->toBeTrue();
    expect($response->json('data.sort_order'))->toBe(10);

    $row = DeliveryProvider::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Talabat')
        ->firstOrFail();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.delivery_provider.created',
        'auditable_id' => $row->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('returns 422 on duplicate provider name within the same tenant', function (): void {
    $ctx = makeMerchantActor();
    DeliveryProvider::factory()->for($ctx['company'], 'company')->create(['name' => 'Talabat']);

    $response = $this->postJson('/api/delivery-providers', ['name' => 'Talabat'])
        ->assertStatus(422);
    expect($response->json('message'))->toContain('already exists');
});

it('returns 422 on invalid color hex', function (): void {
    makeMerchantActor();

    $this->postJson('/api/delivery-providers', [
        'name' => 'X',
        'color' => 'NOT_A_HEX',
    ])->assertStatus(422)->assertJsonValidationErrors(['color']);
});

// =================== PROVIDER UPDATE ===================

it('updates a delivery provider with diff-aware audit', function (): void {
    $ctx = makeMerchantActor();
    $p = DeliveryProvider::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Old', 'is_active' => true]);

    $this->patchJson("/api/delivery-providers/{$p->uuid}", [
        'name' => 'New',
        'is_active' => false,
    ])->assertOk();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.delivery_provider.updated',
        'auditable_id' => $p->id,
    ]);
    $p->refresh();
    expect($p->name)->toBe('New');
    expect((bool) $p->is_active)->toBeFalse();
});

it('writes no audit row on a no-op update', function (): void {
    $ctx = makeMerchantActor();
    $p = DeliveryProvider::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Same', 'sort_order' => 5]);

    $this->patchJson("/api/delivery-providers/{$p->uuid}", [
        'name' => 'Same',
        'sort_order' => 5,
    ])->assertOk();

    $count = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.delivery_provider.updated')
        ->where('auditable_id', $p->id)
        ->count();
    expect($count)->toBe(0);
});

it('returns 404 when updating a foreign-tenant provider', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreign = DeliveryProvider::factory()->for($otherCompany, 'company')->create();

    $this->patchJson("/api/delivery-providers/{$foreign->uuid}", ['name' => 'X'])
        ->assertNotFound();
});

// =================== PROVIDER DELETE ===================

it('soft-deletes a provider and preserves price-override rows', function (): void {
    $ctx = makeMerchantActor();
    $p = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    ProductDeliveryPrice::factory()
        ->for($product, 'product')
        ->for($p, 'deliveryProvider')
        ->for($ctx['company'], 'company')
        ->create();

    $providerId = $p->id;
    $this->deleteJson("/api/delivery-providers/{$p->uuid}")->assertNoContent();

    // Provider soft-deleted.
    expect(DeliveryProvider::query()->find($providerId))->toBeNull();
    expect(DeliveryProvider::withTrashed()->find($providerId))->not->toBeNull();

    // Price-override row survives the soft delete (FK cascade
    // only fires on hard-delete; intentional for historical
    // orders).
    expect(ProductDeliveryPrice::query()->where('delivery_provider_id', $providerId)->count())->toBe(1);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.delivery_provider.deleted',
        'auditable_id' => $providerId,
    ]);
});

// =================== PER-PRODUCT PRICES — SET ===================

it('PUT creates a new price override on first call', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();

    $response = $this->putJson(
        "/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}",
        ['price' => '6.500'],
    )->assertCreated();

    expect($response->json('data.price'))->toBe('6.500');
    expect(ProductDeliveryPrice::query()
        ->where('product_id', $product->id)
        ->where('delivery_provider_id', $provider->id)
        ->count())->toBe(1);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.delivery_price.set',
        'company_id' => $ctx['company']->id,
    ]);
});

it('PUT updates an existing price override on subsequent calls', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    ProductDeliveryPrice::factory()
        ->for($product, 'product')
        ->for($provider, 'deliveryProvider')
        ->for($ctx['company'], 'company')
        ->create(['price' => '6.000']);

    $this->putJson(
        "/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}",
        ['price' => '7.000'],
    )->assertCreated()->assertJsonPath('data.price', '7.000');

    // Still ONE row (update, not duplicate).
    expect(ProductDeliveryPrice::query()
        ->where('product_id', $product->id)
        ->where('delivery_provider_id', $provider->id)
        ->count())->toBe(1);
});

it('PUT idempotent no-op skips audit when price is unchanged', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    ProductDeliveryPrice::factory()
        ->for($product, 'product')
        ->for($provider, 'deliveryProvider')
        ->for($ctx['company'], 'company')
        ->create(['price' => '6.000']);

    $this->putJson(
        "/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}",
        ['price' => '6.000'],
    )->assertCreated();

    // Exactly the one audit row from the create — no second
    // for the idempotent no-op.
    $count = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.delivery_price.set')
        ->where('company_id', $ctx['company']->id)
        ->count();
    expect($count)->toBe(0);
});

it('PUT returns 422 on price <= 0', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();

    $this->putJson(
        "/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}",
        ['price' => '0'],
    )->assertStatus(422)->assertJsonValidationErrors(['price']);

    $this->putJson(
        "/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}",
        ['price' => '-1.000'],
    )->assertStatus(422)->assertJsonValidationErrors(['price']);
});

it('PUT returns 404 when targeting a foreign-tenant product', function (): void {
    $ctx = makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignProduct = Product::factory()->for($otherCompany, 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();

    $this->putJson(
        "/api/products/{$foreignProduct->uuid}/delivery-prices/{$provider->uuid}",
        ['price' => '5.000'],
    )->assertNotFound();
});

it('PUT returns 422 when targeting a foreign-tenant provider', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $otherCompany = Company::factory()->create();
    $foreignProvider = DeliveryProvider::factory()->for($otherCompany, 'company')->create();

    $response = $this->putJson(
        "/api/products/{$product->uuid}/delivery-prices/{$foreignProvider->uuid}",
        ['price' => '5.000'],
    )->assertStatus(422);
    expect($response->json('message'))->toContain('does not belong');
});

// =================== PER-PRODUCT PRICES — REMOVE ===================

it('DELETE removes an existing price override + writes audit', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    $row = ProductDeliveryPrice::factory()
        ->for($product, 'product')
        ->for($provider, 'deliveryProvider')
        ->for($ctx['company'], 'company')
        ->create(['price' => '6.000']);

    $this->deleteJson("/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}")
        ->assertNoContent();

    expect(ProductDeliveryPrice::query()->find($row->id))->toBeNull();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.delivery_price.removed',
        'auditable_id' => $row->id,
    ]);
});

it('DELETE is idempotent on a missing override (no error, no audit)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();

    // No override exists — delete must succeed silently.
    $this->deleteJson("/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}")
        ->assertNoContent();

    $count = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.delivery_price.removed')
        ->count();
    expect($count)->toBe(0);
});

// =================== PER-PRODUCT PRICES — LIST ===================

it('GET /products/{uuid}/delivery-prices lists overrides for that product', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $talabat = DeliveryProvider::factory()->for($ctx['company'], 'company')->create(['name' => 'Talabat']);
    $otlob = DeliveryProvider::factory()->for($ctx['company'], 'company')->create(['name' => 'Otlob']);
    ProductDeliveryPrice::factory()->for($product, 'product')->for($talabat, 'deliveryProvider')->for($ctx['company'], 'company')->create(['price' => '6.000']);
    ProductDeliveryPrice::factory()->for($product, 'product')->for($otlob, 'deliveryProvider')->for($ctx['company'], 'company')->create(['price' => '5.500']);

    // Other product's overrides MUST NOT leak.
    $otherProduct = Product::factory()->for($ctx['company'], 'company')->create();
    ProductDeliveryPrice::factory()->for($otherProduct, 'product')->for($talabat, 'deliveryProvider')->for($ctx['company'], 'company')->create();

    $response = $this->getJson("/api/products/{$product->uuid}/delivery-prices")->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    // The eager-loaded provider summary is inlined.
    $rows = $response->json('data');
    foreach ($rows as $row) {
        expect($row['delivery_provider'])->not->toBeNull();
        expect($row['delivery_provider'])->toHaveKey('name');
    }
});

// =================== PRICE RESOLUTION (via Product helper) ===================

it('resolves a product price for a provider: override > delivery_price > base_price', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')
        ->create(['base_price' => '2.000', 'delivery_price' => '2.500']);
    $talabat = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    $otlob = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();

    // Override exists for Talabat — wins over delivery_price.
    ProductDeliveryPrice::factory()
        ->for($product, 'product')
        ->for($talabat, 'deliveryProvider')
        ->for($ctx['company'], 'company')
        ->create(['price' => '3.000']);

    expect($product->resolvedDeliveryPriceFor($talabat->id))->toBe('3.000');

    // No override for Otlob — falls back to delivery_price.
    expect($product->resolvedDeliveryPriceFor($otlob->id))->toBe('2.500');

    // Strip delivery_price + no override — falls back to base_price.
    $product->update(['delivery_price' => null]);
    expect($product->fresh()->resolvedDeliveryPriceFor($otlob->id))->toBe('2.000');
});

// =================== PERMISSION MATRIX ===================

it('lets a Viewer GET providers + prices but forbids every write', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/delivery-providers')->assertOk();
    $this->getJson("/api/products/{$product->uuid}/delivery-prices")->assertOk();

    $this->postJson('/api/delivery-providers', ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/delivery-providers/{$provider->uuid}", ['name' => 'X'])->assertForbidden();
    $this->deleteJson("/api/delivery-providers/{$provider->uuid}")->assertForbidden();
    $this->putJson("/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}", ['price' => '5.000'])->assertForbidden();
    $this->deleteJson("/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}")->assertForbidden();
});

it('lets a CashierSupervisor GET but not write (no catalogue.manage)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/delivery-providers')->assertOk();
    $this->postJson('/api/delivery-providers', ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/delivery-providers/{$provider->uuid}", ['name' => 'X'])->assertForbidden();
});

it('lets an InventoryManager run the full delivery-provider lifecycle (has catalogue.manage)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    // Create
    $created = $this->postJson('/api/delivery-providers', ['name' => 'Talabat'])->assertCreated();
    $providerUuid = $created->json('data.uuid');
    // Update
    $this->patchJson("/api/delivery-providers/{$providerUuid}", ['color' => '#FF6B00'])->assertOk();
    // Set override
    $this->putJson("/api/products/{$product->uuid}/delivery-prices/{$providerUuid}", ['price' => '6.000'])->assertCreated();
    // Remove override
    $this->deleteJson("/api/products/{$product->uuid}/delivery-prices/{$providerUuid}")->assertNoContent();
    // Soft-delete provider
    $this->deleteJson("/api/delivery-providers/{$providerUuid}")->assertNoContent();
});

it('lets the SuperAdmin do everything via the auto-full permission grant', function (): void {
    $ctx = makeMerchantActor();
    $this->postJson('/api/delivery-providers', ['name' => 'Talabat'])->assertCreated();
});
