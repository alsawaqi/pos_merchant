<?php

declare(strict_types=1);

/**
 * P-G2 — physical items (cups / lids / boxes).
 *
 *   PUT /api/products/{uuid}/components   full-replace of the per-unit
 *                                         component list (catalogue.manage)
 *   GET /api/products/component-options   slim unit-product picker source
 *
 * Plus the is_internal flag (never on the POS menu / tablet; full stock
 * participation). Covers the guards: components must be unit-mode +
 * same-company, no self-reference, no duplicates.
 */

use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coffeeAndCups(array $ctx): array
{
    $coffee = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee', 'stock_mode' => 'ingredient']);
    $cup = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cup 12oz', 'stock_mode' => 'unit', 'is_internal' => true]);
    $lid = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Lid', 'stock_mode' => 'unit', 'is_internal' => true]);

    return [$coffee, $cup, $lid];
}

it('sets, replaces, and clears the physical items of a product', function (): void {
    $ctx = makeMerchantActor();
    [$coffee, $cup, $lid] = coffeeAndCups($ctx);

    // Set: 1 cup + 1 lid per coffee.
    $res = $this->putJson("/api/products/{$coffee->uuid}/components", [
        'lines' => [
            ['component_uuid' => $cup->uuid, 'quantity' => 1],
            ['component_uuid' => $lid->uuid, 'quantity' => 1],
        ],
    ])->assertOk();

    $lines = collect($res->json('data.component_lines'));
    expect($lines)->toHaveCount(2);
    expect($lines->pluck('component_name')->all())->toContain('Cup 12oz', 'Lid');

    $this->assertDatabaseCount('pos_product_components', 2);

    // Replace: only the cup, 2 per unit.
    $res = $this->putJson("/api/products/{$coffee->uuid}/components", [
        'lines' => [['component_uuid' => $cup->uuid, 'quantity' => 2]],
    ])->assertOk();
    expect($res->json('data.component_lines'))->toHaveCount(1);
    expect($res->json('data.component_lines.0.quantity'))->toBe('2.000');

    // Clear.
    $this->putJson("/api/products/{$coffee->uuid}/components", ['lines' => []])->assertOk();
    $this->assertDatabaseCount('pos_product_components', 0);

    // Audited.
    $this->assertDatabaseHas('pos_audit_logs', [
        'company_id' => $ctx['company']->id,
        'event' => 'catalogue.product.components_updated',
    ]);
});

it('rejects a non-unit component', function (): void {
    $ctx = makeMerchantActor();
    [$coffee] = coffeeAndCups($ctx);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'cooked']);

    $this->putJson("/api/products/{$coffee->uuid}/components", [
        'lines' => [['component_uuid' => $cake->uuid, 'quantity' => 1]],
    ])->assertStatus(422);

    $this->assertDatabaseCount('pos_product_components', 0);
});

it('rejects a cross-tenant component and a self-reference and duplicates', function (): void {
    $ctx = makeMerchantActor();
    [$coffee, $cup] = coffeeAndCups($ctx);

    $foreign = Product::factory()
        ->for(Company::factory()->create(), 'company')
        ->create(['stock_mode' => 'unit']);
    $this->putJson("/api/products/{$coffee->uuid}/components", [
        'lines' => [['component_uuid' => $foreign->uuid, 'quantity' => 1]],
    ])->assertStatus(422);

    // Self-reference (a unit product consuming itself).
    $this->putJson("/api/products/{$cup->uuid}/components", [
        'lines' => [['component_uuid' => $cup->uuid, 'quantity' => 1]],
    ])->assertStatus(422);

    // Duplicates.
    $this->putJson("/api/products/{$coffee->uuid}/components", [
        'lines' => [
            ['component_uuid' => $cup->uuid, 'quantity' => 1],
            ['component_uuid' => $cup->uuid, 'quantity' => 2],
        ],
    ])->assertStatus(422);

    $this->assertDatabaseCount('pos_product_components', 0);
});

it('persists the internal-item flag', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->postJson('/api/products', [
        'name' => 'Cup 8oz',
        'base_price' => '0',
        'stock_mode' => 'unit',
        'is_internal' => true,
    ])->assertCreated();

    expect($res->json('data.is_internal'))->toBeTrue();

    $product = Product::query()
        ->where('company_id', $ctx['company']->id)
        ->where('name', 'Cup 8oz')
        ->firstOrFail();
    expect($product->is_internal)->toBeTrue();

    // And it can flip back to a sellable product.
    $this->patchJson("/api/products/{$product->uuid}", ['is_internal' => false])->assertOk();
    expect($product->fresh()->is_internal)->toBeFalse();
});

it('lists only unit products as component options, internal first', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Zebra Cup', 'stock_mode' => 'unit', 'is_internal' => true]);
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Apple Juice', 'stock_mode' => 'unit', 'is_internal' => false]);
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'stock_mode' => 'ingredient']);
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'cooked']);
    // Another company's unit product never leaks.
    Product::factory()->for(Company::factory()->create(), 'company')->create(['name' => 'Foreign Cup', 'stock_mode' => 'unit']);

    $options = $this->getJson('/api/products/component-options')->assertOk()->json('data');

    expect(collect($options)->pluck('name')->all())->toBe(['Zebra Cup', 'Apple Juice']);
    expect($options[0]['is_internal'])->toBeTrue();
});
