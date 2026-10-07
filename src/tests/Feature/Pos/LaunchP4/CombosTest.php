<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B2, rebuilt by the LAUNCH combo add-on (LAUNCH-COMBO_WORK_ORDER.md
 * Part A item 2): a combo is a set price plus LINES — included items (a
 * product × quantity, with upgrades at an upgrade price) and choices ("pick
 * N from a category", unticked items, extra prices). A combo keeps no stock,
 * recipe or components of its own and is listed with the products. Before:
 * a combo was built from choice slots only.
 */

use App\Enums\MerchantRole;
use App\Models\AddOnGroup;
use App\Models\ComboLine;
use App\Models\ComboLineItem;
use App\Models\ComboLineUpgrade;
use App\Models\Company;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** @return array{burger: Product, wrap: Product, fries: Product, loaded: Product, cola: Product, juice: Product, water: Product, drinks: ProductCategory} */
function p4ComboItems(Company $company): array
{
    $drinks = ProductCategory::factory()->for($company, 'company')->create(['name' => 'Drinks']);

    return [
        'burger' => p4Product($company, 'Burger', '2.000'),
        'wrap' => p4Product($company, 'Wrap', '1.800'),
        'fries' => p4Product($company, 'Fries', '0.700'),
        'loaded' => p4Product($company, 'Loaded fries', '1.500'),
        'cola' => p4Product($company, 'Cola', '0.400', ['category_id' => $drinks->id]),
        'juice' => p4Product($company, 'Juice', '0.900', ['category_id' => $drinks->id]),
        'water' => p4Product($company, 'Water', '0.200', ['category_id' => $drinks->id]),
        'drinks' => $drinks,
    ];
}

/** @param array<string, mixed> $items */
function p4ComboPayload(array $items, array $extra = []): array
{
    return array_merge([
        'name' => 'Family box',
        'name_ar' => 'صندوق العائلة',
        'base_price' => '5.000',
        'delivery_price' => '5.500',
        'sold_in_store' => true,
        'show_on_customer_tablet' => true,
        'sold_on_delivery' => true,
        'lines' => [
            ['kind' => 'fixed', 'product_uuid' => $items['burger']->uuid, 'quantity' => 2, 'upgrades' => []],
            ['kind' => 'fixed', 'product_uuid' => $items['fries']->uuid, 'quantity' => 1, 'upgrades' => [
                ['product_uuid' => $items['loaded']->uuid, 'upgrade_price' => '0.800'],
            ]],
            ['kind' => 'choice', 'name' => 'Drink', 'name_ar' => 'المشروب', 'category_id' => $items['drinks']->id, 'pick_count' => 2, 'items' => [
                ['product_uuid' => $items['juice']->uuid, 'excluded' => false, 'extra_price' => '0.300'],
                ['product_uuid' => $items['water']->uuid, 'excluded' => true, 'extra_price' => '0'],
                ['product_uuid' => $items['cola']->uuid, 'excluded' => false, 'extra_price' => '0'],
            ]],
        ],
        'delivery_prices' => [],
        'branches' => null,
    ], $extra);
}

it('creates a combo with included items, upgrades and a choice from a category', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);

    $res = $this->postJson('/api/combos', p4ComboPayload($items))
        ->assertCreated()
        ->assertJsonPath('data.product_type', 'combo')
        ->assertJsonPath('data.stock_mode', 'untracked')
        ->assertJsonPath('data.combo.lines.0.kind', 'fixed')
        ->assertJsonPath('data.combo.lines.0.product_uuid', $items['burger']->uuid)
        ->assertJsonPath('data.combo.lines.0.quantity', 2)
        ->assertJsonPath('data.combo.lines.1.upgrades.0.product_uuid', $items['loaded']->uuid)
        ->assertJsonPath('data.combo.lines.1.upgrades.0.upgrade_price', '0.800')
        ->assertJsonPath('data.combo.lines.2.kind', 'choice')
        ->assertJsonPath('data.combo.lines.2.name_ar', 'المشروب')
        ->assertJsonPath('data.combo.lines.2.pick_count', 2)
        ->assertJsonPath('data.combo.lines.2.category_name', 'Drinks');

    $combo = Product::query()->where('uuid', $res->json('data.uuid'))->sole();
    expect($combo->product_type)->toBe('combo')
        ->and((string) $combo->base_price)->toBe('5.000')
        ->and(ComboLine::query()->where('combo_product_id', $combo->id)->orderBy('sort_order')->pluck('kind')->all())->toBe(['fixed', 'fixed', 'choice']);
    // Only the overrides that change something are stored: Juice +0.300 and the unticked Water (Cola is in, free).
    $choice = ComboLine::query()->where('combo_product_id', $combo->id)->where('kind', 'choice')->sole();
    expect(ComboLineItem::query()->where('line_id', $choice->id)->orderBy('id')->get()->map(fn ($i) => [$i->product_id, (bool) $i->excluded, (string) $i->extra_price])->all())
        ->toBe([[$items['juice']->id, false, '0.300'], [$items['water']->id, true, '0.000']]);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.combo.lines_saved', 'auditable_id' => $combo->id]);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.product.created', 'auditable_id' => $combo->id]);

    // Listed with the products, with its type.
    $row = collect($this->getJson('/api/products')->json('data'))->firstWhere('uuid', $combo->uuid);
    expect($row['product_type'])->toBe('combo');
});

it('saves a combo keeping line ids, rewriting upgrades and overrides and dropping removed lines', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $data = $this->postJson('/api/combos', p4ComboPayload($items))->assertCreated()->json('data');
    [$burgerLine, $friesLine, $drinkLine] = array_column($data['combo']['lines'], 'id');

    $this->putJson("/api/combos/{$data['uuid']}", p4ComboPayload($items, [
        'base_price' => '5.250',
        'lines' => [
            ['id' => $friesLine, 'kind' => 'fixed', 'product_uuid' => $items['fries']->uuid, 'quantity' => 2, 'upgrades' => []],
            ['id' => $drinkLine, 'kind' => 'choice', 'name' => 'Drinks', 'category_id' => $items['drinks']->id, 'pick_count' => 1, 'items' => []],
            ['kind' => 'fixed', 'product_uuid' => $items['wrap']->uuid, 'quantity' => 1],
        ],
    ]))->assertOk()->assertJsonPath('data.base_price', '5.250');

    $combo = Product::query()->where('uuid', $data['uuid'])->sole();
    $lines = ComboLine::query()->where('combo_product_id', $combo->id)->orderBy('sort_order')->get();
    expect($lines->pluck('id')->take(2)->all())->toBe([$friesLine, $drinkLine])
        ->and($lines->pluck('quantity')->all())->toBe([2, null, 1])
        ->and(ComboLine::query()->find($burgerLine))->toBeNull()
        ->and(ComboLineUpgrade::query()->where('line_id', $friesLine)->count())->toBe(0)
        ->and(ComboLineItem::query()->where('line_id', $drinkLine)->count())->toBe(0);
});

it('moves the combo updated_at when only its lines change, so devices re-read it', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $payload = p4ComboPayload($items);
    $data = $this->postJson('/api/combos', $payload)->assertCreated()->json('data');
    DB::table('pos_products')->where('uuid', $data['uuid'])->update(['updated_at' => now()->subDay()]);

    // Same product fields; the upgrade price changes.
    $payload['lines'][1]['id'] = $data['combo']['lines'][1]['id'];
    $payload['lines'][1]['upgrades'][0]['upgrade_price'] = '0.900';
    $this->putJson("/api/combos/{$data['uuid']}", $payload)->assertOk();

    expect((string) ComboLineUpgrade::query()->sole()->upgrade_price)->toBe('0.900')
        ->and(DB::table('pos_products')->where('uuid', $data['uuid'])->value('updated_at'))->toBeGreaterThan(now()->subHour()->toDateTimeString());
});

it('gives a combo no add-ons of its own', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid');
    $shared = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Sauces']);

    $this->putJson("/api/products/{$uuid}/addon-groups", ['group_uuids' => [$shared->uuid]])->assertStatus(422);
    $this->postJson("/api/products/{$uuid}/addon-groups", ['name' => 'Meal extras'])->assertStatus(422);
    expect(AddOnGroup::query()->where('name', 'Meal extras')->exists())->toBeFalse()
        ->and(DB::table('pos_addon_group_products')->count())->toBe(0);
});

it('refuses items that are not menu products of this company, and another company\'s category', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $combo = Product::query()->where('uuid', $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid'))->sole();
    $cup = p4Product($ctx['company'], 'Cup', '0', ['is_internal' => true, 'stock_mode' => 'unit']);
    $other = Company::factory()->create();
    $foreign = p4Product($other, 'Foreign', '1.000');
    $foreignCategory = ProductCategory::factory()->for($other, 'company')->create(['name' => 'Theirs']);

    foreach ([$combo, $cup, $foreign] as $bad) {
        $payload = p4ComboPayload($items, ['name' => 'Bad '.$bad->id]);
        $payload['lines'][0]['product_uuid'] = $bad->uuid;
        $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['lines.0.product_uuid']);
        $payload = p4ComboPayload($items, ['name' => 'Bad upgrade '.$bad->id]);
        $payload['lines'][1]['upgrades'][] = ['product_uuid' => $bad->uuid, 'upgrade_price' => '0'];
        $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['lines.1.upgrades.1.product_uuid']);
    }
    // A combo never contains itself.
    $payload = p4ComboPayload($items);
    $payload['lines'][0]['product_uuid'] = $combo->uuid;
    $this->putJson("/api/combos/{$combo->uuid}", $payload)->assertStatus(422)->assertJsonValidationErrors(['lines.0.product_uuid']);
    // Tenancy: another merchant's category, or their product as a choice override.
    $payload = p4ComboPayload($items, ['name' => 'Bad category']);
    $payload['lines'][2]['category_id'] = $foreignCategory->id;
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['lines.2.category_id']);
    $payload = p4ComboPayload($items, ['name' => 'Bad item']);
    $payload['lines'][2]['items'][] = ['product_uuid' => $foreign->uuid, 'excluded' => true];
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['lines.2.items.3.product_uuid']);
    expect(Product::query()->where('product_type', 'combo')->count())->toBe(1);
});

it('checks the line rules: quantity, pick N, upgrades, the category\'s items and at least one line', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $bad = function (callable $change, string $field) use ($items): void {
        $payload = p4ComboPayload($items);
        $change($payload);
        $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors([$field]);
    };

    $bad(function (array &$p): void {
        $p['lines'][0]['quantity'] = 0;
    }, 'lines.0.quantity');
    $bad(function (array &$p): void {
        $p['lines'][2]['pick_count'] = 21;
    }, 'lines.2.pick_count');
    $bad(function (array &$p): void {
        unset($p['lines'][2]['name']);
    }, 'lines.2.name');
    $bad(function (array &$p) use ($items): void {
        $p['lines'][1]['upgrades'][0]['product_uuid'] = $items['fries']->uuid;
    }, 'lines.1.upgrades.0.product_uuid');
    $bad(function (array &$p): void {
        $p['lines'][1]['upgrades'][] = $p['lines'][1]['upgrades'][0];
    }, 'lines.1.upgrades.1.product_uuid');
    $bad(function (array &$p): void {
        $p['lines'][1]['upgrades'][0]['upgrade_price'] = '-0.100';
    }, 'lines.1.upgrades.0.upgrade_price');
    $bad(function (array &$p): void {
        $p['lines'][2]['items'][0]['extra_price'] = '-0.100';
    }, 'lines.2.items.0.extra_price');
    // An override for a product of another category, and a choice with nothing left to pick.
    $bad(function (array &$p) use ($items): void {
        $p['lines'][2]['items'][] = ['product_uuid' => $items['burger']->uuid, 'excluded' => true];
    }, 'lines.2.items.3.product_uuid');
    $bad(function (array &$p): void {
        foreach ($p['lines'][2]['items'] as &$row) {
            $row['excluded'] = true;
        }
    }, 'lines.2.items');
    $this->postJson('/api/combos', p4ComboPayload($items, ['lines' => []]))->assertStatus(422)->assertJsonValidationErrors(['lines']);

    expect(Product::query()->where('product_type', 'combo')->count())->toBe(0);
});

it('refuses a line id that belongs to another combo', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $first = $this->postJson('/api/combos', p4ComboPayload($items))->json('data');
    $second = $this->postJson('/api/combos', p4ComboPayload($items, ['name' => 'Wrap box']))->json('data');

    $payload = p4ComboPayload($items);
    $payload['lines'][0]['id'] = $first['combo']['lines'][0]['id'];
    $this->putJson("/api/combos/{$second['uuid']}", $payload)->assertStatus(422)->assertJsonValidationErrors(['lines.0.id']);
});

it('gives a combo its channels and its own delivery-provider rows', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $talabat = DeliveryProvider::factory()->for($ctx['company'], 'company')->create();
    $branchB = p4Branch($ctx['company'], 'Seeb');

    $uuid = $this->postJson('/api/combos', p4ComboPayload($items, [
        'show_on_customer_tablet' => false,
        'delivery_prices' => [['provider_uuid' => $talabat->uuid, 'listed' => false, 'price' => null]],
        'branches' => ['branch_scope' => 'selected', 'branch_ids' => [$branchB->id]],
    ]))->assertCreated()->assertJsonPath('data.show_on_customer_tablet', false)->json('data.uuid');

    $combo = Product::query()->where('uuid', $uuid)->sole();
    expect($combo->branch_scope)->toBe('selected');
    expect((bool) DB::table('pos_product_delivery_prices')->where('product_id', $combo->id)->value('listed'))->toBeFalse();
    $this->getJson("/api/combos/{$uuid}")->assertOk()->assertJsonPath('data.delivery_provider_prices.0.listed', false);
});

it('keeps a combo free of stock, recipe and components of its own', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $cup = p4Product($ctx['company'], 'Cup', '0', ['is_internal' => true, 'stock_mode' => 'unit', 'internal_purpose' => 'packaging']);
    $uuid = $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid');

    $this->putJson("/api/products/{$uuid}/components", ['lines' => [['component_uuid' => $cup->uuid, 'quantity' => 1]]])
        ->assertStatus(422);
    $this->patchJson("/api/products/{$uuid}", ['stock_mode' => 'unit'])->assertStatus(422);
    expect(Product::query()->where('uuid', $uuid)->value('stock_mode'))->toBe('untracked');
});

it('will not delete an included item, an upgrade or a choice category while a combo uses them', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid');

    foreach (['burger', 'loaded'] as $key) {
        $this->deleteJson("/api/products/{$items[$key]->uuid}")->assertStatus(422)
            ->assertJsonPath('message', 'This item is in a combo or meal: remove it from Family box first. / هذا الصنف داخل كومبو أو وجبة: أزله منها أولاً.');
    }
    // A product of a choice category may go: the choice no longer offers it.
    $this->deleteJson("/api/products/{$items['juice']->uuid}")->assertNoContent();
    $this->deleteJson("/api/categories/{$items['drinks']->uuid}")->assertStatus(422);

    // Once the combo is gone, the items can go.
    $this->deleteJson("/api/products/{$uuid}")->assertNoContent();
    $this->deleteJson("/api/products/{$items['loaded']->uuid}")->assertNoContent();
});

it('never offers a combo as an add-on', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid');
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Extras']);

    $this->postJson("/api/addon-groups/{$group->uuid}/addons", ['name' => 'Meal', 'price_delta' => '1.000', 'linked_product_uuid' => $uuid])
        ->assertStatus(422);
    $links = collect($this->getJson('/api/products/addon-link-options')->json('data'));
    expect($links->pluck('uuid'))->not->toContain($uuid)->and($links->pluck('uuid'))->toContain($items['burger']->uuid)
        // LAUNCH combo add-on — the editors read each item's category.
        ->and($links->firstWhere('uuid', $items['cola']->uuid)['category_id'])->toBe($items['drinks']->id);
});

it('lets a catalogue viewer read a combo but not save one, and keeps combos per company', function (): void {
    $owner = makeMerchantActor();
    $items = p4ComboItems($owner['company']);
    $uuid = $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid');
    $this->getJson("/api/combos/{$items['burger']->uuid}")->assertNotFound();

    // A viewer of the same company reads it but cannot save it.
    $viewer = User::factory()->create(['company_id' => $owner['company']->id, 'user_type' => 'merchant', 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($owner['company']->id);
    $viewer->assignRole(MerchantRole::Viewer->value);
    $this->actingAs($viewer);
    $this->getJson("/api/combos/{$uuid}")->assertOk()->assertJsonPath('data.combo.lines.2.name', 'Drink');
    $this->putJson("/api/combos/{$uuid}", p4ComboPayload($items))->assertForbidden();

    // Another company never sees it.
    makeMerchantActor();
    $this->getJson("/api/combos/{$uuid}")->assertNotFound();
});
