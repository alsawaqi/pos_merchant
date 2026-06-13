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

it('ignores is_internal on the catalogue endpoints (PD3a: physical items live in Inventory)', function (): void {
    $ctx = makeMerchantActor();

    // A catalogue create can no longer mint an internal item…
    $res = $this->postJson('/api/products', [
        'name' => 'Cup 8oz',
        'base_price' => '0',
        'stock_mode' => 'unit',
        'is_internal' => true,
    ])->assertCreated();
    expect($res->json('data.is_internal'))->toBeFalse();

    // …and the catalogue endpoints 404 physical items entirely (their
    // only management surface is /api/physical-items, inventory-gated —
    // a catalogue.manage-only user must not rename / re-mode / delete
    // them through this side door).
    $item = Product::factory()->for($ctx['company'], 'company')->create([
        'stock_mode' => 'unit', 'is_internal' => true, 'name' => 'Real Cup',
    ]);
    $this->patchJson("/api/products/{$item->uuid}", ['name' => 'X'])->assertNotFound();
    $this->getJson("/api/products/{$item->uuid}")->assertNotFound();
    $this->deleteJson("/api/products/{$item->uuid}")->assertNotFound();
    expect($item->fresh()->is_internal)->toBeTrue();
});

it('rejects branch-use components and physical-item parents on the write path', function (): void {
    $ctx = makeMerchantActor();
    $coffee = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee', 'stock_mode' => 'ingredient']);
    $bulb = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Bulb', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'general']);
    $cup = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cup', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'packaging']);

    // A branch-use item can never be attached to food.
    $this->putJson("/api/products/{$coffee->uuid}/components", [
        'lines' => [['component_uuid' => $bulb->uuid, 'quantity' => 1]],
    ])->assertStatus(422);

    // A physical item consumes nothing itself.
    $this->putJson("/api/products/{$cup->uuid}/components", [
        'lines' => [['component_uuid' => $cup->uuid, 'quantity' => 1]],
    ])->assertStatus(422);

    $this->assertDatabaseCount('pos_product_components', 0);
});

it('lists only used-with-food physical items as component options (PD3a)', function (): void {
    $ctx = makeMerchantActor();
    // Legacy pre-PD3a item (purpose NULL) = packaging until edited.
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Zebra Cup', 'stock_mode' => 'unit', 'is_internal' => true]);
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Meal Box', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'packaging']);
    // Branch-use items (bulbs…) are never attachable to food.
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Light Bulb', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'general']);
    // Sellable unit products are not physical items.
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Apple Juice', 'stock_mode' => 'unit', 'is_internal' => false]);
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'stock_mode' => 'ingredient']);
    // Another company's item never leaks.
    Product::factory()->for(Company::factory()->create(), 'company')->create(['name' => 'Foreign Cup', 'stock_mode' => 'unit', 'is_internal' => true]);

    $options = $this->getJson('/api/products/component-options')->assertOk()->json('data');

    expect(collect($options)->pluck('name')->all())->toBe(['Meal Box', 'Zebra Cup']);
});
