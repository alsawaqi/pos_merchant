<?php

declare(strict_types=1);

/**
 * LAUNCH packaging add-on, part B2 — Inventory → "Order packaging" (owner
 * decision 3, tester calls 5 and 8, work order §3 Part B2).
 *
 * One list per merchant per order type (Dine in, Quick order, To go,
 * Delivery), each line an ingredient (a unit of its kind, stored in its base
 * unit and as typed) or a physical item (pieces, or a pack of it). Read:
 * catalogue.view or inventory.view; write: "Edit recipes". Tenant-scoped,
 * prep items and branch-use / non-piece items refused, one line per item,
 * audited; an unchanged list is a no-op; a removed item can come back.
 * Before (81a59b4): no such endpoint (404).
 */

use App\Enums\MerchantPermission;
use App\Models\Company;
use App\Models\ProductPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function pkPack(\App\Models\Product $item, string $name, string $pieces): ProductPack
{
    $id = DB::table('pos_product_packs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => $item->company_id,
        'product_id' => $item->id,
        'name' => $name,
        'pieces' => $pieces,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ProductPack::query()->withoutGlobalScopes()->findOrFail($id);
}

function pkPackToken(string $uuid): string
{
    return '#'.rtrim(strtr(base64_encode((string) hex2bin(str_replace('-', '', $uuid))), '+/', '-_'), '=');
}

it('reads four empty lists, and saves a To go list of an ingredient in kg, a bag in pieces and napkins by the pack', function (): void {
    $ctx = makeMerchantActor();
    $sugar = pkIngredient($ctx['company'], 'Sugar', 'g', '0.001');
    $bag = pkItem($ctx['company'], 'Paper bag', '0.040');
    $napkin = pkItem($ctx['company'], 'Napkin', '0.002');
    $pack = pkPack($napkin, 'pack', '50');

    $this->getJson('/api/inventory/order-packaging')->assertOk()
        ->assertJsonPath('data.can_edit', true)
        ->assertJsonPath('data.lists.dine_in.lines', [])
        ->assertJsonPath('data.lists.quick.lines', [])
        ->assertJsonPath('data.lists.to_go.lines', [])
        ->assertJsonPath('data.lists.delivery.lines', []);

    $response = $this->putJson('/api/inventory/order-packaging/to_go', ['lines' => [
        ['type' => 'ingredient', 'ingredient_uuid' => $sugar->uuid, 'quantity' => '0.01', 'unit' => 'kg'],
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1'],
        ['type' => 'product', 'product_uuid' => $napkin->uuid, 'quantity' => '1', 'unit' => pkPackToken($pack->uuid)],
    ]])->assertOk();

    $response->assertJsonPath('data.lists.to_go.lines.0.ingredient_uuid', $sugar->uuid)
        ->assertJsonPath('data.lists.to_go.lines.0.entered_unit', 'kg')
        ->assertJsonPath('data.lists.to_go.lines.2.pack_uuid', $pack->uuid)
        ->assertJsonPath('data.lists.to_go.cost', '0.150')
        ->assertJsonPath('data.lists.dine_in.lines', []);

    $rows = DB::table('pos_order_packaging_lines')->where('company_id', $ctx['company']->id)->whereNull('deleted_at')->orderBy('sort_order')->get();
    expect($rows->map(fn ($r) => [$r->order_type, $r->ingredient_id !== null ? (int) $r->ingredient_id : null, $r->product_id !== null ? (int) $r->product_id : null, (float) $r->quantity, $r->unit])->all())
        ->toBe([
            ['to_go', $sugar->id, null, 10.0, 'g'],
            ['to_go', null, $bag->id, 1.0, null],
            ['to_go', null, $napkin->id, 50.0, null],
        ])
        ->and($rows[0]->entered_unit)->toBe('kg')
        ->and((float) $rows[0]->entered_quantity)->toBe(0.01);

    $audit = pkAudit('inventory.order_packaging_updated');
    expect($audit['old'])->toBe(['order_type' => 'to_go', 'lines' => []])
        ->and($audit['new']['lines'])->toBe(['Sugar: 10 g', 'Paper bag: 1 piece', 'Napkin: 50 piece']);
});

it('is a no-op when nothing changed, and brings a removed item back on the same row', function (): void {
    $ctx = makeMerchantActor();
    $bag = pkItem($ctx['company'], 'Bag');
    $sugar = pkIngredient($ctx['company'], 'Sugar', 'g');

    $this->putJson('/api/inventory/order-packaging/delivery', ['lines' => [
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1'],
        ['type' => 'ingredient', 'ingredient_uuid' => $sugar->uuid, 'quantity' => '10'],
    ]])->assertOk();
    $audits = DB::table('pos_audit_logs')->where('event', 'inventory.order_packaging_updated')->count();

    $this->putJson('/api/inventory/order-packaging/delivery', ['lines' => [
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1'],
        ['type' => 'ingredient', 'ingredient_uuid' => $sugar->uuid, 'quantity' => '10', 'unit' => 'g'],
    ]])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'inventory.order_packaging_updated')->count())->toBe($audits);

    $bagRow = DB::table('pos_order_packaging_lines')->where('product_id', $bag->id)->value('id');
    $this->putJson('/api/inventory/order-packaging/delivery', ['lines' => []])->assertOk()->assertJsonPath('data.lists.delivery.lines', []);
    expect(DB::table('pos_order_packaging_lines')->whereNull('deleted_at')->count())->toBe(0);
    $this->putJson('/api/inventory/order-packaging/delivery', ['lines' => [
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '2'],
    ]])->assertOk()->assertJsonPath('data.lists.delivery.lines.0.quantity', '2.000');
    expect((int) DB::table('pos_order_packaging_lines')->whereNull('deleted_at')->value('id'))->toBe((int) $bagRow);
});

it('refuses another company\'s item, a prep item, a branch-use or made-to-order item, a duplicate, a bad pack and zero', function (): void {
    $ctx = makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = pkItem($other, 'Their cup');
    $prep = pkIngredient($ctx['company'], 'Syrup', 'ml', '0', ['is_prep' => true, 'prep_yield_quantity' => '1000']);
    $bulb = pkItem($ctx['company'], 'Bulb', '0.500', 'general');
    $latte = pkProduct($ctx['company'], 'Latte');
    $bag = pkItem($ctx['company'], 'Bag');
    $otherPack = pkPack(pkItem($ctx['company'], 'Napkin'), 'pack', '50');

    $put = fn (array $lines) => $this->putJson('/api/inventory/order-packaging/quick', ['lines' => $lines]);
    $put([['type' => 'product', 'product_uuid' => $foreign->uuid, 'quantity' => '1']])->assertStatus(422);
    $put([['type' => 'ingredient', 'ingredient_uuid' => $prep->uuid, 'quantity' => '10']])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'prep item'));
    $put([['type' => 'product', 'product_uuid' => $bulb->uuid, 'quantity' => '1']])->assertStatus(422);
    $put([['type' => 'product', 'product_uuid' => $latte->uuid, 'quantity' => '1']])->assertStatus(422);
    $put([
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1'],
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '2'],
    ])->assertStatus(422);
    $put([['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1', 'unit' => pkPackToken($otherPack->uuid)]])->assertStatus(422);
    $put([['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '0']])->assertStatus(422);
    $this->putJson('/api/inventory/order-packaging/car', ['lines' => []])->assertNotFound();

    expect(DB::table('pos_order_packaging_lines')->count())->toBe(0);
});

it('lets catalogue or inventory viewers read, and only "Edit recipes" write', function (): void {
    $viewer = pkActorWith([MerchantPermission::InventoryView->value]);
    $bag = pkItem($viewer['company'], 'Bag');
    DB::table('pos_order_packaging_lines')->insert(['company_id' => $viewer['company']->id, 'order_type' => 'to_go', 'product_id' => $bag->id, 'quantity' => '1', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/api/inventory/order-packaging')->assertOk()
        ->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('data.lists.to_go.lines.0.name', 'Bag');

    $inventoryManager = pkActorWith([MerchantPermission::InventoryView->value, MerchantPermission::InventoryManage->value]);
    $bag2 = pkItem($inventoryManager['company'], 'Bag');
    $this->putJson('/api/inventory/order-packaging/to_go', ['lines' => [['type' => 'product', 'product_uuid' => $bag2->uuid, 'quantity' => '1']]])->assertForbidden();

    $chef = pkActorWith([MerchantPermission::CatalogueView->value, MerchantPermission::CatalogueRecipesManage->value]);
    $bag3 = pkItem($chef['company'], 'Bag');
    $this->putJson('/api/inventory/order-packaging/to_go', ['lines' => [['type' => 'product', 'product_uuid' => $bag3->uuid, 'quantity' => '1']]])->assertOk();

    pkActorWith([MerchantPermission::BranchesView->value]);
    $this->getJson('/api/inventory/order-packaging')->assertForbidden();
});

it('shows only this company\'s lists', function (): void {
    $other = makeMerchantActor();
    $theirBag = pkItem($other['company'], 'Their bag');
    DB::table('pos_order_packaging_lines')->insert(['company_id' => $other['company']->id, 'order_type' => 'to_go', 'product_id' => $theirBag->id, 'quantity' => '1', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

    makeMerchantActor();
    $this->getJson('/api/inventory/order-packaging')->assertOk()->assertJsonPath('data.lists.to_go.lines', []);
});
