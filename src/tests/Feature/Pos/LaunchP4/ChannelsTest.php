<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B3 — channels on the product form (owner decision 8): in store,
 * QR menu (show_on_customer_tablet) and delivery, with a per-provider "listed"
 * tick and price (blank = the delivery price). Plus L5, the Arabic
 * description. Before: no in-store / delivery switches, a provider row always
 * needed a price, and there was no description_ar.
 */

use App\Models\DeliveryProvider;
use App\Models\ProductDeliveryPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function p4WizardPayload(array $product, array $extra = []): array
{
    return array_merge([
        'product' => array_merge(['name' => 'Chicken shawarma', 'base_price' => '1.500', 'stock_mode' => 'untracked'], $product),
        'addon_group_uuids' => [],
        'owned_groups' => [],
        'recipe_lines' => [],
        'component_lines' => [],
        'branches' => null,
        'delivery_prices' => [],
    ], $extra);
}

it('creates a product with its channels, Arabic description and per-provider listing', function (): void {
    $ctx = makeMerchantActor();
    $talabat = DeliveryProvider::factory()->for($ctx['company'], 'company')->create(['name' => 'Talabat']);
    $otlob = DeliveryProvider::factory()->for($ctx['company'], 'company')->create(['name' => 'Otlob']);

    $this->postJson('/api/products/wizard', p4WizardPayload([
        'sold_in_store' => true,
        'sold_on_delivery' => true,
        'show_on_customer_tablet' => false,
        'delivery_price' => '1.800',
        'description_ar' => 'شاورما دجاج مع ثومية',
    ], [
        'delivery_prices' => [
            ['provider_uuid' => $talabat->uuid, 'listed' => false, 'price' => null],
            ['provider_uuid' => $otlob->uuid, 'listed' => true, 'price' => '2.100'],
        ],
    ]))->assertCreated()
        ->assertJsonPath('data.sold_in_store', true)
        ->assertJsonPath('data.sold_on_delivery', true)
        ->assertJsonPath('data.show_on_customer_tablet', false)
        ->assertJsonPath('data.description_ar', 'شاورما دجاج مع ثومية');

    $product = DB::table('pos_products')->where('name', 'Chicken shawarma')->first();
    expect((bool) $product->show_on_customer_tablet)->toBeFalse()
        ->and($product->description_ar)->toBe('شاورما دجاج مع ثومية');

    $hidden = ProductDeliveryPrice::query()->where('product_id', $product->id)->where('delivery_provider_id', $talabat->id)->sole();
    expect($hidden->listed)->toBeFalse()->and($hidden->price)->toBeNull();
    $own = ProductDeliveryPrice::query()->where('product_id', $product->id)->where('delivery_provider_id', $otlob->id)->sole();
    expect($own->listed)->toBeTrue()->and((string) $own->price)->toBe('2.100');
});

it('switches a product off in store or on delivery and audits it', function (): void {
    $ctx = makeMerchantActor();
    $product = p4Product($ctx['company'], 'Family meal', '6.000');

    $this->patchJson("/api/products/{$product->uuid}", ['sold_in_store' => false, 'sold_on_delivery' => false])
        ->assertOk()
        ->assertJsonPath('data.sold_in_store', false)
        ->assertJsonPath('data.sold_on_delivery', false);

    $row = DB::table('pos_products')->where('id', $product->id)->first();
    expect((bool) $row->sold_in_store)->toBeFalse()->and((bool) $row->sold_on_delivery)->toBeFalse();
    $audit = DB::table('pos_audit_logs')->where('event', 'catalogue.product.updated')->where('auditable_id', $product->id)->latest('id')->first();
    expect(json_decode((string) $audit->new_values, true))->toMatchArray(['sold_in_store' => false, 'sold_on_delivery' => false]);
});

it('hides a product on one provider, lists it at the default price, or gives it its own price', function (): void {
    $ctx = makeMerchantActor();
    $product = p4Product($ctx['company'], 'Burger', '2.000', ['delivery_price' => '2.400']);
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    $url = "/api/products/{$product->uuid}/delivery-prices/{$provider->uuid}";

    // Not listed: a row with no price.
    $this->putJson($url, ['listed' => false])->assertCreated()->assertJsonPath('data.listed', false)->assertJsonPath('data.price', null);
    $row = ProductDeliveryPrice::query()->where('product_id', $product->id)->sole();
    expect($row->listed)->toBeFalse()->and($row->price)->toBeNull();

    // Own price.
    $this->putJson($url, ['listed' => true, 'price' => '2.750'])->assertCreated()->assertJsonPath('data.price', '2.750');
    expect($product->fresh()->resolvedDeliveryPriceFor($provider->id))->toBe('2.750');

    // Listed at the default price: no row needed — the delivery price applies.
    $this->putJson($url, ['listed' => true, 'price' => null])->assertOk()->assertJsonPath('data', null);
    expect(ProductDeliveryPrice::query()->where('product_id', $product->id)->exists())->toBeFalse();
    expect($product->fresh()->resolvedDeliveryPriceFor($provider->id))->toBe('2.400');
});

it('falls back to the delivery price when a provider row carries only the listed flag', function (): void {
    $ctx = makeMerchantActor();
    $product = p4Product($ctx['company'], 'Fries', '0.800');
    $provider = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    DB::table('pos_product_delivery_prices')->insert([
        'product_id' => $product->id, 'delivery_provider_id' => $provider->id, 'company_id' => $ctx['company']->id,
        'price' => null, 'listed' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($product->fresh()->resolvedDeliveryPriceFor($provider->id))->toBe('0.800');
    $this->getJson("/api/products/{$product->uuid}/delivery-prices")
        ->assertOk()
        ->assertJsonPath('data.0.price', null)
        ->assertJsonPath('data.0.listed', true);
});

it('shows the channels of every product in the catalogue list', function (): void {
    $ctx = makeMerchantActor();
    p4Product($ctx['company'], 'Dine-in only', '3.000', ['sold_on_delivery' => false, 'show_on_customer_tablet' => false]);

    $row = collect($this->getJson('/api/products')->assertOk()->json('data'))->firstWhere('name', 'Dine-in only');
    expect($row)->toMatchArray([
        'sold_in_store' => true,
        'sold_on_delivery' => false,
        'show_on_customer_tablet' => false,
        'product_type' => 'standard',
        'branch_scope' => 'all',
    ]);
});

it('caps the Arabic description like the English one', function (): void {
    $ctx = makeMerchantActor();
    $product = p4Product($ctx['company'], 'Soup', '1.000');

    $this->patchJson("/api/products/{$product->uuid}", ['description_ar' => str_repeat('ش', 1001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description_ar']);
    $this->patchJson("/api/products/{$product->uuid}", ['description_ar' => 'حساء العدس'])
        ->assertOk()
        ->assertJsonPath('data.description_ar', 'حساء العدس');
});
