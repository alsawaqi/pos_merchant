<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 10 — F the scan box (server side).
 *
 *   GET  /api/inventory/scan?code=   lookup order: a container / pack barcode
 *        → a product or physical-item barcode → an exact SKU (case-insensitive);
 *        company-scoped; the item only (never a balance);
 *   POST /api/inventory/scan/link    an unknown code is linked to an item and
 *        container (inventory.manage) and remembered.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\ItemBarcode;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('finds a container barcode first, then a product barcode, then a SKU', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0', ['sku' => 'MILK-01']);
    $bottle = rvContainer($milk, 'bottle', '1500');
    ItemBarcode::query()->create(['company_id' => $ctx['company']->id, 'barcode' => '0001', 'ingredient_id' => $milk->id, 'container_id' => $bottle, 'label' => 'Brand A']);
    $cup = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cup', 'is_internal' => true, 'stock_mode' => 'unit', 'barcode' => '0002', 'sku' => 'PHY-0001', 'base_price' => 0]);

    $this->getJson('/api/inventory/scan?code=0001')->assertOk()
        ->assertJsonPath('data.found', true)
        ->assertJsonPath('data.matched_by', 'barcode')
        ->assertJsonPath('data.item_type', 'ingredient')
        ->assertJsonPath('data.item.uuid', $milk->uuid)
        ->assertJsonPath('data.container.uuid', rvContainerUuid($bottle))
        ->assertJsonPath('data.container.token', rvToken($bottle))
        ->assertJsonPath('data.container.display_name', 'bottle 1.5 l')
        ->assertJsonPath('data.label', 'Brand A');

    $this->getJson('/api/inventory/scan?code=0002')->assertOk()
        ->assertJsonPath('data.matched_by', 'product_barcode')
        ->assertJsonPath('data.item_type', 'physical')
        ->assertJsonPath('data.item.uuid', $cup->uuid);

    $this->getJson('/api/inventory/scan?code=milk-01')->assertOk()
        ->assertJsonPath('data.matched_by', 'sku')
        ->assertJsonPath('data.item.uuid', $milk->uuid)
        ->assertJsonPath('data.container', null);

    $res = $this->getJson('/api/inventory/scan?code=nothing')->assertOk();
    expect($res->json('data.found'))->toBeFalse()->and($res->json('data.can_link'))->toBeTrue();
    // The item only: no stock numbers ride the result.
    expect(array_keys((array) $this->getJson('/api/inventory/scan?code=0001')->json('data.item')))->not->toContain('quantity');
});

it('links an unknown barcode to an item and container and remembers it', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');

    $this->postJson('/api/inventory/scan/link', [
        'code' => '6281000000000', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'label' => 'Al Safi',
    ])->assertCreated()
        ->assertJsonPath('data.found', true)
        ->assertJsonPath('data.container.uuid', rvContainerUuid($bottle));

    $this->getJson('/api/inventory/scan?code=6281000000000')->assertOk()->assertJsonPath('data.item.uuid', $milk->uuid);
});

it('stays within the company', function (): void {
    $ctx = makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = rvIngredient($other, 'Foreign', 'ml', '0', ['sku' => 'F-1']);
    ItemBarcode::query()->create(['company_id' => $other->id, 'barcode' => '777', 'ingredient_id' => $foreign->id]);

    $this->getJson('/api/inventory/scan?code=777')->assertOk()->assertJsonPath('data.found', false);
    $this->getJson('/api/inventory/scan?code=F-1')->assertOk()->assertJsonPath('data.found', false);
    $this->postJson('/api/inventory/scan/link', ['code' => '888', 'item_type' => 'ingredient', 'item_uuid' => $foreign->uuid])->assertStatus(422);
});

it('lets a view-only user scan but not link', function (): void {
    // The Viewer role reads inventory but does not manage it.
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');

    $this->getJson('/api/inventory/scan?code=unknown')->assertOk()
        ->assertJsonPath('data.found', false)
        ->assertJsonPath('data.can_link', false);
    $this->postJson('/api/inventory/scan/link', ['code' => 'unknown', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid])->assertForbidden();
    $this->postJson('/api/inventory/barcodes', ['barcode' => 'unknown', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid])->assertForbidden();
});
