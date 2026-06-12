<?php

declare(strict_types=1);

/**
 * P-G3 — product-as-add-on (cake inside a coffee).
 *
 *   - add-ons accept linked_product_uuid (company-owned, non-internal),
 *     emit the linked product, and can unlink (back to label-only);
 *   - GET /api/products/addon-link-options = the sellable picker source;
 *   - the Product Performance report counts add-on sales into the linked
 *     product's numbers (addon_units / addon_revenue), including
 *     products sold ONLY as add-ons.
 */

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('links a product to an add-on and unlinks it again', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Extras']);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'cooked']);

    // Create with the link.
    $res = $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Cake slice',
        'price_delta' => '1.500',
        'linked_product_uuid' => $cake->uuid,
    ])->assertCreated();

    expect($res->json('data.linked_product_id'))->toBe($cake->id);
    expect($res->json('data.linked_product.name'))->toBe('Cake');
    expect($res->json('data.linked_product.stock_mode'))->toBe('cooked');

    $addon = AddOn::query()->where('name', 'Cake slice')->firstOrFail();
    expect((int) $addon->linked_product_id)->toBe($cake->id);

    // Unlink (back to a classic label-only option).
    $res = $this->patchJson("/api/addons/{$addon->uuid}", ['linked_product_uuid' => null])->assertOk();
    expect($res->json('data.linked_product_id'))->toBeNull();
    expect($addon->fresh()->linked_product_id)->toBeNull();
});

it('rejects a cross-tenant or internal linked product', function (): void {
    $ctx = makeMerchantActor();
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create();

    $foreign = Product::factory()->for(Company::factory()->create(), 'company')->create();
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Sneaky',
        'linked_product_uuid' => $foreign->uuid,
    ])->assertStatus(422);

    $cup = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'is_internal' => true]);
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Cup?',
        'linked_product_uuid' => $cup->uuid,
    ])->assertStatus(422);

    expect(AddOn::query()->count())->toBe(0);
});

it('lists only sellable products as addon link options', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'cooked']);
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cup', 'stock_mode' => 'unit', 'is_internal' => true]);
    Product::factory()->for(Company::factory()->create(), 'company')->create(['name' => 'Foreign']);

    $options = $this->getJson('/api/products/addon-link-options')->assertOk()->json('data');

    expect(collect($options)->pluck('name')->all())->toBe(['Cake']);
});

it('counts add-on sales into the linked products performance numbers', function (): void {
    $ctx = makeMerchantActor();
    $coffee = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee', 'base_price' => '1.500']);
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cake', 'stock_mode' => 'cooked', 'base_price' => '5.000']);

    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'opened_at' => '2026-06-10 12:00:00',
    ]);
    // 2 coffees, each carrying the cake add-on at 1.500.
    $item = OrderItem::factory()->for($order, 'order')->for($coffee, 'product')->create([
        'product_name_snapshot' => 'Coffee',
        'qty' => '2.000',
        'unit_price_snapshot' => '3.000',
        'line_total' => '6.000',
    ]);
    DB::table('pos_order_item_addons')->insert([
        'order_item_id' => $item->id,
        'add_on_id' => null,
        'add_on_name_snapshot' => 'Cake slice',
        'price_delta_snapshot' => '1.500',
        'linked_product_id' => $cake->id,
        'product_snapshot_json' => json_encode(['product_id' => $cake->id, 'stock_mode' => 'cooked', 'recipe' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $res = $this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    $rows = collect($res->json('data.top_by_revenue'));

    // The cake never sold standalone but earns a row from its add-on sales:
    // 2 units (parent qty), revenue 2 x 1.500 = 3.000.
    $cakeRow = $rows->firstWhere('product_name', 'Cake');
    expect($cakeRow)->not->toBeNull();
    expect((float) $cakeRow['qty_sold'])->toBe(0.0);
    expect((float) $cakeRow['addon_units'])->toBe(2.0);
    expect($cakeRow['addon_revenue'])->toBe('3.000');

    // The coffee keeps its own standalone numbers, no add-on columns.
    $coffeeRow = $rows->firstWhere('product_name', 'Coffee');
    expect((float) $coffeeRow['qty_sold'])->toBe(2.0);
    expect((float) $coffeeRow['addon_units'])->toBe(0.0);
});
