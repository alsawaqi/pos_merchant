<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 4 — A4 SKU and A5 barcodes (owner
 * decision D8, tester calls 11 and 12).
 *
 *   - SKU per ingredient and per physical item: the supplier's code, or
 *     generated (ING-0001 / PHY-0001, next = highest + 1, skipping taken);
 *     unique per company, CASE-INSENSITIVE, across BOTH tables, in both
 *     directions (the product, combo, wizard and menu-import checks too);
 *     "Generate missing SKUs" for physical items without one;
 *   - barcodes per container (several), per ingredient and per physical item
 *     piece / pack; kept as a trimmed string (leading zeros kept); unique per
 *     company across pos_item_barcodes and pos_products.barcode, both ways.
 */

use App\Models\Ingredient;
use App\Models\ItemBarcode;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('generates ING SKUs for ingredients and keeps a supplier code', function (): void {
    $ctx = makeMerchantActor();
    rvIngredient($ctx['company'], 'Old', 'g', '0', ['sku' => 'ING-0041']);

    $this->postJson('/api/ingredients', ['name' => 'Milk', 'unit' => 'ml'])->assertCreated()->assertJsonPath('data.sku', 'ING-0042');
    $this->postJson('/api/ingredients', ['name' => 'Oil', 'unit' => 'ml', 'sku' => 'SUP-77'])->assertCreated()->assertJsonPath('data.sku', 'SUP-77');

    // Unique across ingredients, case-insensitive.
    $this->postJson('/api/ingredients', ['name' => 'Ghee', 'unit' => 'g', 'sku' => 'sup-77'])->assertStatus(422)->assertJsonValidationErrors(['sku']);

    // A blank SKU on edit keeps the code.
    $oil = Ingredient::query()->where('name', 'Oil')->firstOrFail();
    $this->patchJson("/api/ingredients/{$oil->uuid}", ['sku' => ''])->assertOk()->assertJsonPath('data.sku', 'SUP-77');
});

it('skips a generated code already taken by a product', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create(['sku' => 'ing-0001']);

    $this->postJson('/api/ingredients', ['name' => 'Milk', 'unit' => 'ml'])->assertCreated()->assertJsonPath('data.sku', 'ING-0002');

    // A deleted row's code is skipped too (the set pos_admin's back-fill skips).
    $gone = rvIngredient($ctx['company'], 'Gone', 'g', '0', ['sku' => 'ING-0003']);
    $gone->delete();
    $this->postJson('/api/ingredients', ['name' => 'Oil', 'unit' => 'ml'])->assertCreated()->assertJsonPath('data.sku', 'ING-0004');
});

it('keeps SKUs unique across ingredients and products in both directions', function (): void {
    $ctx = makeMerchantActor();
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['sku' => 'LAT-1', 'stock_mode' => 'untracked']);
    rvIngredient($ctx['company'], 'Milk', 'ml', '0', ['sku' => 'MILK-1']);

    // Ingredient side: a product's SKU is taken (any case).
    $this->postJson('/api/ingredients', ['name' => 'Beans', 'unit' => 'g', 'sku' => 'lat-1'])->assertStatus(422);

    // Product side: an ingredient's SKU is taken — update, create, combo.
    $this->patchJson("/api/products/{$latte->uuid}", ['sku' => 'milk-1'])->assertStatus(422)->assertJsonValidationErrors(['sku']);
    $this->postJson('/api/products', ['name' => 'Mocha', 'base_price' => '1.500', 'sku' => 'MILK-1'])->assertStatus(422)->assertJsonValidationErrors(['sku']);
    $burger = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Burger', 'stock_mode' => 'untracked', 'product_type' => 'standard', 'status' => 'active']);
    $this->postJson('/api/combos', [
        'name' => 'Meal', 'base_price' => '2.000', 'sku' => 'Milk-1',
        'sold_in_store' => true, 'show_on_customer_tablet' => true, 'sold_on_delivery' => true,
        'slots' => [['name' => 'Main', 'min_choices' => 1, 'max_choices' => 1, 'options' => [['product_uuid' => $burger->uuid, 'extra_price' => '0', 'is_default' => true]]]],
        'delivery_prices' => [], 'branches' => null,
    ])->assertStatus(422)->assertJsonValidationErrors(['sku']);

    // And a product's own SKU is still its own (no false clash with itself).
    $this->patchJson("/api/products/{$latte->uuid}", ['sku' => 'LAT-1', 'name' => 'Latte'])->assertOk();
});

it('generates PHY SKUs for physical items, on save and with "Generate missing SKUs"', function (): void {
    $ctx = makeMerchantActor();

    $cup = $this->postJson('/api/physical-items', ['name' => 'Cup', 'purpose' => 'packaging'])->assertCreated()->json('data');
    expect($cup['sku'])->toBe('PHY-0001');

    // Two older items with no SKU.
    $lid = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Lid', 'is_internal' => true, 'stock_mode' => 'unit', 'sku' => null, 'base_price' => 0]);
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Bulb', 'is_internal' => true, 'stock_mode' => 'unit', 'sku' => null, 'base_price' => 0]);

    // One gets it on its next save …
    $this->patchJson("/api/physical-items/{$lid->uuid}", ['name' => 'Lid 80mm'])->assertOk()->assertJsonPath('data.sku', 'PHY-0002');
    // … the rest with the button.
    $this->postJson('/api/physical-items/generate-skus')->assertOk()->assertJsonPath('data.generated', 1);
    expect(Product::query()->where('name', 'Bulb')->value('sku'))->toBe('PHY-0003');

    // A typed SKU must be free across both tables.
    rvIngredient($ctx['company'], 'Milk', 'ml', '0', ['sku' => 'M-1']);
    $this->postJson('/api/physical-items', ['name' => 'Straw', 'purpose' => 'packaging', 'sku' => 'm-1'])->assertStatus(422);
});

it('keeps several barcodes per container, with leading zeros, unique across both tables', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1500');

    $first = $this->postJson('/api/inventory/barcodes', [
        'barcode' => ' 0012345678905 ', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'label' => 'Brand A',
    ])->assertCreated();
    expect($first->json('data.barcode'))->toBe('0012345678905');
    $this->postJson('/api/inventory/barcodes', [
        'barcode' => '0098765432109', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'container_uuid' => rvContainerUuid($bottle), 'label' => 'Brand B',
    ])->assertCreated();

    $listed = collect($this->getJson('/api/ingredients')->json('data'))->firstWhere('uuid', $milk->uuid);
    expect(collect($listed['alt_units'][0]['barcodes'])->pluck('barcode')->all())->toBe(['0012345678905', '0098765432109']);

    // Taken: another item barcode, or a product's barcode — and the other way round.
    $this->postJson('/api/inventory/barcodes', ['barcode' => '0012345678905', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid])->assertStatus(422);
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['barcode' => '555', 'stock_mode' => 'untracked']);
    $this->postJson('/api/inventory/barcodes', ['barcode' => '555', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid])->assertStatus(422);
    $this->patchJson("/api/products/{$latte->uuid}", ['barcode' => '0098765432109'])->assertStatus(422)->assertJsonValidationErrors(['barcode']);

    // A container of another item never takes a barcode for this one.
    $oil = rvIngredient($ctx['company'], 'Oil', 'ml');
    $this->postJson('/api/inventory/barcodes', ['barcode' => '777', 'item_type' => 'ingredient', 'item_uuid' => $oil->uuid, 'container_uuid' => rvContainerUuid($bottle)])->assertStatus(422);

    // Removed: the code is free again.
    $this->deleteJson('/api/inventory/barcodes/'.$first->json('data.uuid'))->assertNoContent();
    $this->postJson('/api/inventory/barcodes', ['barcode' => '0012345678905', 'item_type' => 'ingredient', 'item_uuid' => $milk->uuid])->assertCreated();
});

it('saves barcodes typed on the ingredient form, per container and on the item', function (): void {
    makeMerchantActor();

    $res = $this->postJson('/api/ingredients', [
        'name' => 'Milk', 'unit' => 'ml', 'barcodes' => ['111'],
        'pack_sizes' => [['name' => 'bottle', 'amount' => '1', 'unit' => 'l', 'barcodes' => ['222', '333']]],
    ])->assertCreated();

    expect($res->json('data.barcodes.0.barcode'))->toBe('111')
        ->and(collect($res->json('data.alt_units.0.barcodes'))->pluck('barcode')->all())->toBe(['222', '333']);

    // The same code twice in one form is refused.
    $this->postJson('/api/ingredients', [
        'name' => 'Oil', 'unit' => 'ml', 'barcodes' => ['444'],
        'pack_sizes' => [['name' => 'bottle', 'amount' => '1', 'unit' => 'l', 'barcodes' => ['444']]],
    ])->assertStatus(422);
});

it('refuses an ingredient SKU or an item barcode in the menu import', function (): void {
    $ctx = makeMerchantActor();
    rvIngredient($ctx['company'], 'Milk', 'ml', '0', ['sku' => 'MILK-1']);
    DB::table('pos_item_barcodes')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $ctx['company']->id, 'barcode' => '999',
        'ingredient_id' => Ingredient::query()->value('id'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = app(\App\Actions\Pos\Catalogue\MenuImport\PlanMenuImportAction::class)->handle([
        1 => ['name', 'price', 'sku', 'barcode'],
        2 => ['Latte', '1.500', 'milk-1', ''],
        3 => ['Mocha', '1.800', '', '999'],
    ], false);
    $codes = collect($plan['rows'])->mapWithKeys(static fn (array $r): array => [$r['name'] => collect($r['issues'])->pluck('code')->all()]);

    expect($codes['Latte'])->toContain('sku_physical_item')
        ->and($codes['Mocha'])->toContain('barcode_taken');
});

it('keeps the barcode and SKU of an existing item scannable after a rename', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0', ['sku' => 'MILK-1']);
    ItemBarcode::query()->create(['company_id' => $ctx['company']->id, 'barcode' => '123', 'ingredient_id' => $milk->id]);

    $this->patchJson("/api/ingredients/{$milk->uuid}", ['name' => 'Whole milk'])->assertOk();

    $this->getJson('/api/inventory/scan?code=123')->assertOk()->assertJsonPath('data.item.name', 'Whole milk');
    $this->getJson('/api/inventory/scan?code=milk-1')->assertOk()->assertJsonPath('data.matched_by', 'sku');
});
