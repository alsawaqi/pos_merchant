<?php

declare(strict_types=1);

/**
 * LAUNCH combo add-on, Part A fix order 1 (LAUNCH-COMBO_A_FIX_ORDER_1.md):
 *
 *   C-1  changing only a Remove option's minus price moves the group and the
 *        product and is audited
 *   C-4  a product create or a category move never puts a main in two active
 *        meals; an ended meal never counts; the Meals page shows a clash
 *   C-5  the editors can load every product (no 500 cap)
 *   C-6  product performance keeps a meal's revenue under the meal's name
 */

use App\Models\AddOn;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../LaunchRvMenu/helpers.php';
require_once __DIR__.'/../LaunchP4/helpers.php';

/** @return array<string, mixed> */
function f1Menu(Company $company): array
{
    $burgers = ProductCategory::factory()->for($company, 'company')->create(['name' => 'Burgers']);
    $sides = ProductCategory::factory()->for($company, 'company')->create(['name' => 'Sides']);

    return [
        'burgers' => $burgers, 'sides' => $sides,
        'beef' => p4Product($company, 'Beef burger', '2.000', ['category_id' => $burgers->id]),
        'chicken' => p4Product($company, 'Chicken burger', '1.800', ['category_id' => $burgers->id]),
        'fries' => p4Product($company, 'Fries', '1.000', ['category_id' => $sides->id]),
    ];
}

/** @param array<string, mixed> $m */
function f1Meal(array $m, string $name, array $excluded, array $extra = []): array
{
    return array_merge([
        'name' => $name, 'name_ar' => null, 'meal_price' => '1.200', 'category_ids' => [$m['burgers']->id],
        'excluded_product_uuids' => $excluded,
        'lines' => [['kind' => 'fixed', 'product_uuid' => $m['fries']->uuid, 'quantity' => 1, 'upgrades' => []]],
    ], $extra);
}

it('C-1: changing only the minus price of a Remove option moves the group and the product and is audited', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'onion' => $onion] = rvmBurger($ctx['company']);
    rvmTick($burger, [['ingredient_uuid' => $onion->uuid, 'label' => 'Onion', 'label_ar' => null, 'price' => '0']])->assertOk();
    $option = AddOn::query()->where('removes_ingredient_id', $onion->id)->sole();
    DB::table('pos_addon_groups')->where('id', $option->add_on_group_id)->update(['updated_at' => '2026-01-01 00:00:00']);
    DB::table('pos_products')->where('id', $burger->id)->update(['updated_at' => '2026-01-01 00:00:00']);
    $audits = DB::table('pos_audit_logs')->where('event', 'catalogue.product.removable_saved')->count();

    rvmTick($burger, [['ingredient_uuid' => $onion->uuid, 'label' => 'Onion', 'label_ar' => null, 'price' => '-0.100']])->assertOk();

    expect((string) $option->fresh()->price_delta)->toBe('-0.100')
        ->and((string) DB::table('pos_addon_groups')->where('id', $option->add_on_group_id)->value('updated_at'))->not->toBe('2026-01-01 00:00:00')
        ->and((string) DB::table('pos_products')->where('id', $burger->id)->value('updated_at'))->not->toBe('2026-01-01 00:00:00')
        ->and(DB::table('pos_audit_logs')->where('event', 'catalogue.product.removable_saved')->count())->toBe($audits + 1);
    $audit = rvmAudit('catalogue.product.removable_saved', $burger->id);
    expect($audit['old']['removable'][0]['price_delta'])->toBe('0.000')->and($audit['new']['removable'][0]['price_delta'])->toBe('-0.100');
});

it('C-4: a new product in a category shared by two meals is refused, naming both; a category move too', function (): void {
    $ctx = makeMerchantActor();
    $m = f1Menu($ctx['company']);
    // Two active meals on Burgers, each unticking the other's main: no clash today.
    $this->postJson('/api/meals', f1Meal($m, 'Beef meal', [$m['chicken']->uuid]))->assertCreated();
    $this->postJson('/api/meals', f1Meal($m, 'Chicken meal', [$m['beef']->uuid]))->assertCreated();

    $res = $this->postJson('/api/products', ['name' => 'Mushroom burger', 'category_id' => $m['burgers']->id, 'base_price' => '2.200'])
        ->assertStatus(422);
    expect((string) $res->json('message'))->toContain('"Mushroom burger" would be in both')->toContain('Beef meal')->toContain('Chicken meal');
    expect(Product::query()->where('name', 'Mushroom burger')->exists())->toBeFalse();

    // A category move into Burgers is refused the same way, and leaves the product where it was.
    $this->patchJson("/api/products/{$m['fries']->uuid}", ['category_id' => $m['burgers']->id])->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '"Fries" would be in both'));
    expect($m['fries']->fresh()->category_id)->toBe($m['sides']->id);
    // Elsewhere it is fine.
    $this->postJson('/api/products', ['name' => 'Onion rings', 'category_id' => $m['sides']->id, 'base_price' => '0.900'])->assertCreated();
});

it('C-4 (L6): an ended meal never blocks, and the Meals page shows a clash that arose elsewhere', function (): void {
    $ctx = makeMerchantActor();
    $m = f1Menu($ctx['company']);
    $this->postJson('/api/meals', f1Meal($m, 'Beef meal', []))->assertCreated();
    // A meal whose last day has passed covers the same mains without a clash.
    $this->postJson('/api/meals', f1Meal($m, 'Summer meal', [], ['on_sale_from' => '2026-06-01', 'on_sale_until' => '2026-06-30']))->assertCreated();
    $this->postJson('/api/products', ['name' => 'Mushroom burger', 'category_id' => $m['burgers']->id, 'base_price' => '2.200'])->assertCreated();

    // A clash written outside a meal save (an older save, a direct import) shows on the Meals page.
    $other = $this->postJson('/api/meals', f1Meal($m, 'Big meal', [], ['status' => 'inactive']))->assertCreated()->json('data');
    DB::table('pos_meals')->where('uuid', $other['uuid'])->update(['status' => 'active']);
    $big = collect($this->getJson('/api/meals')->assertOk()->json('data'))->firstWhere('uuid', $other['uuid']);
    expect(collect($big['clashes'])->pluck('meal')->unique()->values()->all())->toBe(['Beef meal'])
        ->and(collect($big['clashes'])->pluck('product')->all())->toContain('Beef burger');
    $summer = collect($this->getJson('/api/meals')->json('data'))->firstWhere('name', 'Summer meal');
    expect($summer['clashes'])->toBe([]);
});

it('C-5: the editors load every product, past the 500 cap', function (): void {
    $ctx = makeMerchantActor();
    $drinks = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    $rows = [];
    for ($i = 1; $i <= 600; $i++) {
        $rows[] = ['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'name' => sprintf('Drink %03d', $i), 'base_price' => '0.500',
            'stock_mode' => 'untracked', 'status' => 'active', 'product_type' => 'standard', 'is_internal' => false, 'category_id' => $drinks->id,
            'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('pos_products')->insert($chunk);
    }
    $last = Product::query()->where('name', 'Drink 600')->sole();

    expect(count($this->getJson('/api/products/addon-link-options')->assertOk()->json('data')))->toBe(500);
    $all = collect($this->getJson('/api/products/addon-link-options?all=1')->assertOk()->json('data'));
    expect($all)->toHaveCount(600)->and($all->firstWhere('uuid', $last->uuid)['category_id'])->toBe($drinks->id);

    // A combo override on product 600 is saved and comes back.
    $burger = p4Product($ctx['company'], 'Burger', '2.000');
    $data = $this->postJson('/api/combos', ['name' => 'Big box', 'base_price' => '5.000', 'delivery_price' => null, 'sold_in_store' => true,
        'show_on_customer_tablet' => true, 'sold_on_delivery' => true, 'delivery_prices' => [], 'branches' => null, 'lines' => [
            ['kind' => 'fixed', 'product_uuid' => $burger->uuid, 'quantity' => 1],
            ['kind' => 'choice', 'name' => 'Drink', 'category_id' => $drinks->id, 'pick_count' => 1, 'items' => [
                ['product_uuid' => $last->uuid, 'excluded' => false, 'extra_price' => '0.300']]],
        ]])->assertCreated()->json('data');
    expect($data['combo']['lines'][1]['items'])->toBe([['product_uuid' => $last->uuid, 'product_name' => 'Drink 600', 'excluded' => false, 'extra_price' => '0.300']]);
});

it('C-6: product performance keeps a meal\'s revenue under the meal\'s name', function (): void {
    $ctx = makeMerchantActor();
    $beef = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Beef burger', 'base_price' => '2.000']);
    $fries = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Fries', 'base_price' => '1.000']);
    $mealId = (int) DB::table('pos_meals')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'name' => 'meal',
        'meal_price' => '1.200', 'created_at' => now(), 'updated_at' => now()]);
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create(['opened_at' => '2026-06-10 12:00:00']);
    $parent = OrderItem::factory()->for($order, 'order')->create(['product_id' => null, 'meal_id' => $mealId, 'product_name_snapshot' => 'Beef burger meal',
        'qty' => '1.000', 'unit_price_snapshot' => '3.200', 'line_total' => '3.200']);
    foreach ([[$beef, 'main', 2133], [$fries, 'fixed', 1067]] as [$product, $kind, $share]) {
        OrderItem::factory()->for($order, 'order')->for($product, 'product')->create(['parent_order_item_id' => $parent->id,
            'product_name_snapshot' => $product->name, 'qty' => '1.000', 'unit_price_snapshot' => '0.000', 'line_total' => '0.000',
            'combo_child_kind' => $kind, 'allocated_revenue_baisas' => $share]);
    }
    OrderItem::factory()->for($order, 'order')->for($fries, 'product')->create(['product_name_snapshot' => 'Fries', 'qty' => '1.000',
        'unit_price_snapshot' => '1.000', 'line_total' => '1.000']);

    $data = $this->getJson('/api/reports/product-performance?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data');
    $rows = collect($data['top_by_revenue']);
    $meal = $rows->firstWhere('product_name', 'Beef burger meal');
    expect($meal)->not->toBeNull()
        ->and($meal['product_type'])->toBe('meal')
        ->and($meal['revenue'])->toBe('3.200')
        ->and($meal['row_key'])->toBe('meal:Beef burger meal')
        // Nothing is lost: the meal 3.200 + the fries sold alone 1.000.
        ->and(round($rows->sum(fn (array $r): float => (float) $r['revenue']), 3))->toBe(4.2);
    expect($rows->first()['product_name'])->toBe('Beef burger meal');
});
