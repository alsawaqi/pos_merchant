<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, fix order C-1 (review REVIEW_CD.md, part C):
 *   M1  the Remove list is saved only from the ticks the page loaded:
 *       a stale page gets 409 and nothing is written;
 *   M3  the main-slot rule holds when a payload omits is_main;
 *   M4  Quick instructions (and Remove lists) never take stock in any form,
 *       the legacy single-ingredient fields included;
 *   L1  a lone date against the saved one is a 422, not a database error;
 *   L2  no two "NO …" chips with the same text;
 *   L3  the hidden Remove list never blocks a name the merchant picks;
 *   L4  the removable endpoint authorizes before it validates.
 * Before (d8b99a4): each of these answered 200/201/500 or let the save through.
 */

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\ComboSlot;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

// ---- M1 -----------------------------------------------------------------

it('M1: refuses with 409 a save made from ticks that are no longer the saved ones, and writes nothing', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup, 'onion' => $onion] = rvmBurger($ctx['company']);
    rvmTick($burger, [['ingredient_uuid' => $ketchup->uuid], ['ingredient_uuid' => $onion->uuid]])->assertOk();

    // Page A loaded the two ticks; page B unticked onion since.
    $pageA = $this->getJson("/api/products/{$burger->uuid}/removable")->json('data.lines');
    rvmTick($burger, [['ingredient_uuid' => $ketchup->uuid]])->assertOk();
    $auditRows = DB::table('pos_audit_logs')->where('event', 'catalogue.product.removable_saved')->count();

    // Page A now unticks ketchup, from its stale view: refused, nothing written.
    rvmTick($burger, [['ingredient_uuid' => $onion->uuid]], $pageA)
        ->assertStatus(409)
        ->assertJsonPath('state.lines.0.ingredient_uuid', $ketchup->uuid);
    expect(DB::table('pos_addons')->where('removes_ingredient_id', $ketchup->id)->value('deleted_at'))->toBeNull()
        ->and(DB::table('pos_addons')->where('removes_ingredient_id', $onion->id)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('pos_audit_logs')->where('event', 'catalogue.product.removable_saved')->count())->toBe($auditRows);

    // An empty "loaded" list against a saved list is stale too: never a wipe.
    rvmTick($burger, [], [])->assertStatus(409);
    expect(DB::table('pos_addons')->where('removes_ingredient_id', $ketchup->id)->value('deleted_at'))->toBeNull();

    // The page that loaded the current ticks saves.
    rvmTick($burger, [['ingredient_uuid' => $onion->uuid]])->assertOk()->assertJsonPath('data.lines.0.ingredient_uuid', $onion->uuid);
});

it('M1: needs the loaded ticks, and a recipe line dropped in the same save is not a conflict', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup, 'onion' => $onion, 'bun' => $bun] = rvmBurger($ctx['company']);
    rvmTick($burger, [['ingredient_uuid' => $ketchup->uuid], ['ingredient_uuid' => $onion->uuid]])->assertOk();

    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => []])
        ->assertStatus(422)->assertJsonValidationErrors(['expected']);

    // The wizard saves the recipe (ketchup line deleted), then the ticks it
    // loaded before: still accepted.
    $loaded = $this->getJson("/api/products/{$burger->uuid}/removable")->json('data.lines');
    $this->putJson("/api/products/{$burger->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $onion->uuid, 'quantity' => '15'],
        ['ingredient_uuid' => $bun->uuid, 'quantity' => '1'],
    ]])->assertOk();
    rvmTick($burger, [['ingredient_uuid' => $onion->uuid, 'label' => 'Onions']], $loaded)
        ->assertOk()->assertJsonPath('data.lines.0.name', 'NO Onions');
});

// ---- M3 -----------------------------------------------------------------

it('M3: refuses a save without is_main that leaves the saved main on a slot not picking exactly 1', function (): void {
    $ctx = makeMerchantActor();
    $items = rvmComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', rvmComboPayload($items))->assertCreated()->json('data.uuid');
    $combo = Product::query()->where('uuid', $uuid)->sole();
    $slots = ComboSlot::query()->where('combo_product_id', $combo->id)->orderBy('sort_order')->get();

    $payload = rvmComboPayload($items);
    foreach ($payload['slots'] as $i => $slot) {
        unset($payload['slots'][$i]['is_main']);
        $payload['slots'][$i]['id'] = $slots[$i]->id;
    }
    $payload['slots'][0]['max_choices'] = 2;
    $res = $this->putJson("/api/combos/{$uuid}", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slots.0.is_main']);
    expect($res->json('errors')['slots.0.is_main'][0])->toBe('The main item slot must be pick exactly 1.');
    $slot = ComboSlot::query()->find($slots[0]->id);
    expect($slot->is_main)->toBeTrue()->and($slot->max_choices)->toBe(1);

    // The other slot (not the main) may change freely.
    $payload['slots'][0]['max_choices'] = 1;
    $payload['slots'][1]['max_choices'] = 2;
    $this->putJson("/api/combos/{$uuid}", $payload)->assertOk()->assertJsonPath('data.combo.slots.0.is_main', true);
});

// ---- M4 -----------------------------------------------------------------

it('M4: a group never becomes Quick instructions while an option takes stock in any form', function (): void {
    $ctx = makeMerchantActor();
    $cheese = rvmIngredient($ctx['company'], 'Cheese');
    $cake = rvmProduct($ctx['company'], 'Cake', '1.000');

    // Legacy single-ingredient fields (pre-PD3b), still deducted by pos_api.
    $legacy = $this->postJson('/api/addon-groups', ['name' => 'Legacy toppings'])->assertCreated()->json('data.uuid');
    $option = $this->postJson("/api/addon-groups/{$legacy}/addons", ['name' => 'Extra cheese', 'price_delta' => '0'])->assertCreated()->json('data.uuid');
    DB::table('pos_addons')->where('uuid', $option)->update(['ingredient_id' => $cheese->id, 'ingredient_qty' => '20', 'ingredient_unit' => 'g']);
    $this->patchJson("/api/addon-groups/{$legacy}", ['kind' => 'instructions'])->assertStatus(422)->assertJsonValidationErrors(['kind']);

    // Consumption lines.
    $lines = $this->postJson('/api/addon-groups', ['name' => 'Stocky'])->assertCreated()->json('data.uuid');
    $this->postJson("/api/addon-groups/{$lines}/addons", ['name' => 'More cheese', 'price_delta' => '0', 'consumption' => [
        ['type' => 'ingredient', 'ingredient_uuid' => $cheese->uuid, 'quantity' => '10'],
    ]])->assertCreated();
    $this->patchJson("/api/addon-groups/{$lines}", ['kind' => 'instructions'])->assertStatus(422)->assertJsonValidationErrors(['kind']);

    // A linked product.
    $linked = $this->postJson('/api/addon-groups', ['name' => 'Sides'])->assertCreated()->json('data.uuid');
    $this->postJson("/api/addon-groups/{$linked}/addons", ['name' => 'Cake', 'price_delta' => '0', 'linked_product_uuid' => $cake->uuid])->assertCreated();
    $this->patchJson("/api/addon-groups/{$linked}", ['kind' => 'instructions'])->assertStatus(422)->assertJsonValidationErrors(['kind']);

    expect(AddOnGroup::query()->where('kind', 'instructions')->count())->toBe(0);
});

it('M4: a Remove list option never keeps stock, even when an old row carries some', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup, 'bun' => $bun] = rvmBurger($ctx['company']);
    rvmTick($burger, [['ingredient_uuid' => $ketchup->uuid]])->assertOk();
    $option = AddOn::query()->where('removes_ingredient_id', $ketchup->id)->sole();
    // A damaged row: the legacy trio and a consumption line.
    DB::table('pos_addons')->where('id', $option->id)->update(['ingredient_id' => $bun->id, 'ingredient_qty' => '1', 'ingredient_unit' => 'piece']);
    DB::table('pos_addon_consumptions')->insert(['add_on_id' => $option->id, 'ingredient_id' => $bun->id, 'direction' => 'add', 'quantity' => '1', 'created_at' => now(), 'updated_at' => now()]);

    rvmTick($burger, [['ingredient_uuid' => $ketchup->uuid, 'label' => 'Ketchup']])->assertOk();

    $row = DB::table('pos_addons')->where('id', $option->id)->first();
    expect($row->ingredient_id)->toBeNull()
        ->and($row->ingredient_qty)->toBeNull()
        ->and(DB::table('pos_addon_consumptions')->where('add_on_id', $option->id)->count())->toBe(0)
        ->and(rvmAudit('catalogue.product.removable_saved', $burger->id)['old']['removable'][0]['takes_stock'])->toBeTrue();
});

// ---- L1 -----------------------------------------------------------------

it('L1: a lone date before or after the saved one is a 422, on combos and products', function (): void {
    $ctx = makeMerchantActor();
    $items = rvmComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', rvmComboPayload($items, ['on_sale_from' => '2026-11-10', 'on_sale_until' => '2026-11-20']))->assertCreated()->json('data.uuid');

    $this->putJson("/api/combos/{$uuid}", rvmComboPayload($items, ['on_sale_until' => '2026-11-01']))
        ->assertStatus(422)->assertJsonValidationErrors(['on_sale_until']);
    $this->putJson("/api/combos/{$uuid}", rvmComboPayload($items, ['on_sale_from' => '2026-11-25']))
        ->assertStatus(422)->assertJsonValidationErrors(['on_sale_from']);
    expect(DB::table('pos_products')->where('uuid', $uuid)->value('on_sale_until'))->toBe('2026-11-20');

    $product = rvmProduct($ctx['company'], 'Mandi', '3.500', ['on_sale_until' => '2026-11-20']);
    $this->patchJson("/api/products/{$product->uuid}", ['on_sale_from' => '2026-11-21'])
        ->assertStatus(422)->assertJsonValidationErrors(['on_sale_from']);
});

// ---- L2 -----------------------------------------------------------------

it('L2: refuses two ticked lines that would show the same "NO …" name, in English or Arabic, any case', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup, 'onion' => $onion] = rvmBurger($ctx['company']);

    $res = rvmTick($burger, [
        ['ingredient_uuid' => $ketchup->uuid, 'label' => 'Sauce'],
        ['ingredient_uuid' => $onion->uuid, 'label' => 'sauce'],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.1.label']);
    expect($res->json('errors')['lines.1.label'][0])->toContain('NO sauce');

    rvmTick($burger, [
        ['ingredient_uuid' => $ketchup->uuid, 'label' => 'Ketchup', 'label_ar' => 'صوص'],
        ['ingredient_uuid' => $onion->uuid, 'label' => 'Onion', 'label_ar' => 'صوص'],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.1.label_ar']);
    expect(DB::table('pos_addon_groups')->where('kind', 'remove')->count())->toBe(0);

    // The wizard's create refuses it too.
    $bun = rvmIngredient($ctx['company'], 'Bun 2', null, 'piece');
    $this->postJson('/api/products/wizard', [
        'product' => ['name' => 'Wrap', 'base_price' => '2.000', 'stock_mode' => 'ingredient'],
        'addon_group_uuids' => [], 'owned_groups' => [], 'component_lines' => [], 'branches' => null, 'delivery_prices' => [],
        'recipe_lines' => [['ingredient_uuid' => $ketchup->uuid, 'quantity' => '20'], ['ingredient_uuid' => $bun->uuid, 'quantity' => '1']],
        'removable' => [['ingredient_uuid' => $ketchup->uuid, 'label' => 'Extras'], ['ingredient_uuid' => $bun->uuid, 'label' => 'EXTRAS']],
    ])->assertStatus(422)->assertJsonValidationErrors(['removable.1.label']);
    expect(Product::query()->where('name', 'Wrap')->exists())->toBeFalse();
});

// ---- L3 -----------------------------------------------------------------

it('L3: the hidden Remove list never blocks a name the merchant gives one of the product\'s own groups', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup] = rvmBurger($ctx['company']);
    rvmTick($burger, [['ingredient_uuid' => $ketchup->uuid]])->assertOk();
    $hidden = AddOnGroup::query()->where('kind', 'remove')->sole();

    $mine = $this->postJson("/api/products/{$burger->uuid}/addon-groups", ['name' => 'Remove'])->assertCreated()->json('data.uuid');
    expect($hidden->fresh()->name)->toBe('Remove 2')
        ->and($hidden->fresh()->kind)->toBe('remove')
        ->and(AddOn::query()->where('add_on_group_id', $hidden->id)->count())->toBe(1)
        ->and(collect($this->getJson("/api/products/{$burger->uuid}/addon-groups")->json('data'))->pluck('name')->all())->toBe(['Remove'])
        ->and(rvmAudit('catalogue.addon_group.updated', $hidden->id)['new']['name'])->toBe('Remove 2');

    // Renaming another own group to the Remove list's new name works too.
    $other = $this->postJson("/api/products/{$burger->uuid}/addon-groups", ['name' => 'Toppings'])->assertCreated()->json('data.uuid');
    $this->patchJson("/api/addon-groups/{$other}", ['name' => 'Remove 2'])->assertOk()->assertJsonPath('data.name', 'Remove 2');
    expect($hidden->fresh()->name)->toBe('Remove 3');
    // The merchant's own names stay unique among themselves.
    $this->postJson("/api/products/{$burger->uuid}/addon-groups", ['name' => 'Remove'])->assertStatus(422)->assertJsonValidationErrors(['name']);
    expect($mine)->not->toBeNull();
});

// ---- L4 -----------------------------------------------------------------

it('L4: a user without catalogue.manage gets 403 before any validation', function (): void {
    $ctx = rvmActorWith(['catalogue.view']);
    ['burger' => $burger] = rvmBurger($ctx['company']);

    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => (string) Str::uuid()]]])->assertForbidden();
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => 'nonsense'])->assertForbidden();
});
