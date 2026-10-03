<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B2 — the combos editor (owner decision 7): a set price plus choice
 * slots; each slot offers standard items of the same company with an extra
 * price and an optional default. A combo keeps no stock, recipe or components
 * of its own and is listed with the products. Before: no combos existed (the
 * routes 404 and nothing stops a product from being a combo item).
 */

use App\Enums\MerchantRole;
use App\Models\AddOnGroup;
use App\Models\ComboSlot;
use App\Models\ComboSlotOption;
use App\Models\Company;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** @return array{burger: Product, wrap: Product, fries: Product, salad: Product, cola: Product} */
function p4ComboItems(Company $company): array
{
    return [
        'burger' => p4Product($company, 'Burger', '2.000'),
        'wrap' => p4Product($company, 'Wrap', '1.800'),
        'fries' => p4Product($company, 'Fries', '0.700'),
        'salad' => p4Product($company, 'Salad', '0.900'),
        'cola' => p4Product($company, 'Cola', '0.400'),
    ];
}

/** @param array<string, Product> $items */
function p4ComboPayload(array $items, array $extra = []): array
{
    return array_merge([
        'name' => 'Burger meal',
        'name_ar' => 'وجبة برجر',
        'base_price' => '3.500',
        'delivery_price' => '3.900',
        'sold_in_store' => true,
        'show_on_customer_tablet' => true,
        'sold_on_delivery' => true,
        'slots' => [
            ['name' => 'Main', 'name_ar' => 'الطبق الرئيسي', 'min_choices' => 1, 'max_choices' => 1, 'options' => [
                ['product_uuid' => $items['burger']->uuid, 'extra_price' => '0', 'is_default' => true],
                ['product_uuid' => $items['wrap']->uuid, 'extra_price' => '0.300', 'is_default' => false],
            ]],
            ['name' => 'Side', 'min_choices' => 1, 'max_choices' => 2, 'options' => [
                ['product_uuid' => $items['fries']->uuid, 'extra_price' => '0', 'is_default' => true],
                ['product_uuid' => $items['salad']->uuid, 'extra_price' => '0.250'],
            ]],
        ],
        'delivery_prices' => [],
        'branches' => null,
    ], $extra);
}

it('creates a combo with its slots, items, extra prices and defaults', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);

    $res = $this->postJson('/api/combos', p4ComboPayload($items))
        ->assertCreated()
        ->assertJsonPath('data.product_type', 'combo')
        ->assertJsonPath('data.stock_mode', 'untracked')
        ->assertJsonPath('data.combo.slots.0.name', 'Main')
        ->assertJsonPath('data.combo.slots.0.name_ar', 'الطبق الرئيسي')
        ->assertJsonPath('data.combo.slots.1.max_choices', 2)
        ->assertJsonPath('data.combo.slots.0.options.1.extra_price', '0.300')
        ->assertJsonPath('data.combo.slots.0.options.0.is_default', true);

    $combo = Product::query()->where('uuid', $res->json('data.uuid'))->sole();
    expect($combo->product_type)->toBe('combo')
        ->and((string) $combo->base_price)->toBe('3.500')
        ->and(ComboSlot::query()->where('combo_product_id', $combo->id)->count())->toBe(2)
        ->and(ComboSlotOption::query()->whereIn('slot_id', ComboSlot::query()->where('combo_product_id', $combo->id)->select('id'))->count())->toBe(4);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.combo.slots_saved', 'auditable_id' => $combo->id]);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.product.created', 'auditable_id' => $combo->id]);

    // Listed with the products, with its type.
    $row = collect($this->getJson('/api/products')->json('data'))->firstWhere('uuid', $combo->uuid);
    expect($row['product_type'])->toBe('combo');
});

it('saves a combo keeping slot ids, updating items and dropping removed slots', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', p4ComboPayload($items))->assertCreated()->json('data.uuid');
    $combo = Product::query()->where('uuid', $uuid)->sole();
    $main = ComboSlot::query()->where('combo_product_id', $combo->id)->where('name', 'Main')->sole();
    $side = ComboSlot::query()->where('combo_product_id', $combo->id)->where('name', 'Side')->sole();

    $this->putJson("/api/combos/{$uuid}", p4ComboPayload($items, [
        'base_price' => '3.750',
        'slots' => [
            ['id' => $main->id, 'name' => 'Burger', 'min_choices' => 1, 'max_choices' => 1, 'options' => [
                ['product_uuid' => $items['burger']->uuid, 'extra_price' => '0.100', 'is_default' => true],
            ]],
            ['name' => 'Drink', 'min_choices' => 0, 'max_choices' => 1, 'options' => [
                ['product_uuid' => $items['cola']->uuid, 'extra_price' => '0'],
            ]],
        ],
    ]))->assertOk()->assertJsonPath('data.base_price', '3.750');

    $slots = ComboSlot::query()->where('combo_product_id', $combo->id)->orderBy('sort_order')->get();
    expect($slots->pluck('name')->all())->toBe(['Burger', 'Drink'])
        ->and($slots->first()->id)->toBe($main->id)
        ->and(ComboSlot::query()->find($side->id))->toBeNull();
    $options = ComboSlotOption::query()->where('slot_id', $main->id)->get();
    expect($options)->toHaveCount(1)
        ->and((string) $options->first()->extra_price)->toBe('0.100');
});

it('moves the combo updated_at when only its slots or options change, so devices re-read it', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $payload = p4ComboPayload($items);
    $uuid = $this->postJson('/api/combos', $payload)->assertCreated()->json('data.uuid');
    DB::table('pos_products')->where('uuid', $uuid)->update(['updated_at' => now()->subDay()]);
    $sides = Product::query()->where('uuid', $uuid)->sole()->comboSlots()->where('name', 'Side')->sole();

    // Same product fields; one option deleted from the Side slot.
    $payload['slots'][1]['id'] = $sides->id;
    $payload['slots'][1]['options'] = [$payload['slots'][1]['options'][0]];
    $this->putJson("/api/combos/{$uuid}", $payload)->assertOk();

    expect(ComboSlotOption::query()->where('slot_id', $sides->id)->count())->toBe(1)
        ->and(DB::table('pos_products')->where('uuid', $uuid)->value('updated_at'))->toBeGreaterThan(now()->subHour()->toDateTimeString());
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

it('refuses combo items that are not menu products of this company', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $combo = Product::query()->where('uuid', $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid'))->sole();
    $cup = p4Product($ctx['company'], 'Cup', '0', ['is_internal' => true, 'stock_mode' => 'unit']);
    $foreign = p4Product(Company::factory()->create(), 'Foreign', '1.000');

    foreach ([$combo, $cup, $foreign] as $bad) {
        $payload = p4ComboPayload($items, ['name' => 'Bad '.$bad->id]);
        $payload['slots'][0]['options'][] = ['product_uuid' => $bad->uuid, 'extra_price' => '0'];
        $this->postJson('/api/combos', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slots.0.options.2.product_uuid']);
    }
});

it('checks the slot rules: least and most, duplicates, defaults and at least one slot', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);

    $payload = p4ComboPayload($items);
    $payload['slots'][1]['min_choices'] = 3;
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['slots.1.max_choices']);

    $payload = p4ComboPayload($items);
    $payload['slots'][0]['options'][1]['product_uuid'] = $items['burger']->uuid;
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['slots.0.options.1.product_uuid']);

    $payload = p4ComboPayload($items);
    $payload['slots'][0]['options'][1]['is_default'] = true;
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['slots.0.options']);

    $this->postJson('/api/combos', p4ComboPayload($items, ['slots' => []]))->assertStatus(422)->assertJsonValidationErrors(['slots']);

    $payload = p4ComboPayload($items);
    $payload['slots'][0]['options'][0]['extra_price'] = '-0.100';
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['slots.0.options.0.extra_price']);

    expect(Product::query()->where('product_type', 'combo')->count())->toBe(0);
});

it('refuses a slot id that belongs to another combo', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $first = $this->postJson('/api/combos', p4ComboPayload($items))->json('data');
    $second = $this->postJson('/api/combos', p4ComboPayload($items, ['name' => 'Wrap meal']))->json('data');

    $payload = p4ComboPayload($items);
    $payload['slots'][0]['id'] = $first['combo']['slots'][0]['id'];
    $this->putJson("/api/combos/{$second['uuid']}", $payload)->assertStatus(422)->assertJsonValidationErrors(['slots.0.id']);
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

it('will not delete an item still offered in a combo', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid');

    $this->deleteJson("/api/products/{$items['salad']->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This item is offered in a combo: remove it from Burger meal first.');
    expect(Product::query()->find($items['salad']->id))->not->toBeNull();

    // Once the combo is gone, the item can go.
    $this->deleteJson("/api/products/{$uuid}")->assertNoContent();
    $this->deleteJson("/api/products/{$items['salad']->uuid}")->assertNoContent();
});

it('never offers a combo as an add-on', function (): void {
    $ctx = makeMerchantActor();
    $items = p4ComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', p4ComboPayload($items))->json('data.uuid');
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Extras']);

    $this->postJson("/api/addon-groups/{$group->uuid}/addons", ['name' => 'Meal', 'price_delta' => '1.000', 'linked_product_uuid' => $uuid])
        ->assertStatus(422);
    $links = collect($this->getJson('/api/products/addon-link-options')->json('data'))->pluck('uuid');
    expect($links)->not->toContain($uuid)->and($links)->toContain($items['burger']->uuid);
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
    $this->getJson("/api/combos/{$uuid}")->assertOk()->assertJsonPath('data.combo.slots.0.name', 'Main');
    $this->putJson("/api/combos/{$uuid}", p4ComboPayload($items))->assertForbidden();

    // Another company never sees it.
    makeMerchantActor();
    $this->getJson("/api/combos/{$uuid}")->assertNotFound();
});
