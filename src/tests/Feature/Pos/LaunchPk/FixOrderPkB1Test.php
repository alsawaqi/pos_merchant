<?php

declare(strict_types=1);

/**
 * LAUNCH packaging add-on, fix order PK-B1 (review B: H1, M1–M4, L1–L4).
 *
 * An item on a per-order packaging list must stay something pos_api takes,
 * the way it was typed: its base unit is locked (H1); a pack it is typed in
 * is "in use" (M1); it cannot be deleted from any endpoint (M2), made
 * inactive, branch-use or not counted in pieces (M3) — "Remove it from the
 * Order packaging list first", in English and Arabic. The wizard can switch
 * to cooked and merge split lines in one save (M4); a switch to cooked sets
 * every line to all order types (L1). The list save is locked per company and
 * order type (L2); refusals carry Arabic (L3); the editor's pickers come with
 * the lists (L4).
 * Before (3bba01d): every one of these edits went through.
 */

use App\Enums\MerchantPermission;
use App\Support\Inventory\OrderPackagingLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function pkb1Pack(\App\Models\Product $item, string $pieces): object
{
    $uuid = (string) Str::uuid();
    DB::table('pos_product_packs')->insert(['uuid' => $uuid, 'company_id' => $item->company_id, 'product_id' => $item->id, 'name' => 'pack', 'pieces' => $pieces, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('pos_product_packs')->where('uuid', $uuid)->first();
}

function pkb1Token(string $uuid): string
{
    return '#'.rtrim(strtr(base64_encode((string) hex2bin(str_replace('-', '', $uuid))), '+/', '-_'), '=');
}

function pkb1List(string $type, array $lines)
{
    return test()->putJson("/api/inventory/order-packaging/{$type}", ['lines' => $lines]);
}

it('H1 locks the base unit of an ingredient on a packaging list', function (): void {
    $ctx = makeMerchantActor();
    $sauce = pkIngredient($ctx['company'], 'Sauce', 'g');
    pkb1List('to_go', [['type' => 'ingredient', 'ingredient_uuid' => $sauce->uuid, 'quantity' => '10']])->assertOk();

    $this->patchJson("/api/ingredients/{$sauce->uuid}", ['unit' => 'kg'])->assertStatus(422);
    expect(DB::table('pos_ingredients')->where('id', $sauce->id)->value('unit'))->toBe('g');
    $row = collect($this->getJson('/api/ingredients')->assertOk()->json('data'))->firstWhere('uuid', $sauce->uuid);
    expect($row['unit_locked'])->toBeTrue();

    // Off the list (and unused elsewhere): the unit can change again.
    pkb1List('to_go', [])->assertOk();
    $this->patchJson("/api/ingredients/{$sauce->uuid}", ['unit' => 'kg'])->assertOk();
});

it('M1 locks the size of a pack used on a packaging list, and reads a pack form that no longer adds up as pieces', function (): void {
    $ctx = makeMerchantActor();
    $napkin = pkItem($ctx['company'], 'Napkin', '0.002');
    $pack = pkb1Pack($napkin, '50');
    pkb1List('to_go', [['type' => 'product', 'product_uuid' => $napkin->uuid, 'quantity' => '1', 'unit' => pkb1Token($pack->uuid)]])->assertOk();

    $this->patchJson("/api/physical-items/{$napkin->uuid}/packs/{$pack->uuid}", ['pieces' => 100])->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Order packaging list'));
    expect((float) DB::table('pos_product_packs')->where('id', $pack->id)->value('pieces'))->toBe(50.0);

    // A stored pack form that no longer adds up (older data) reads as pieces,
    // and re-saving the list untouched changes nothing.
    DB::table('pos_product_packs')->where('id', $pack->id)->update(['pieces' => '100']);
    $line = $this->getJson('/api/inventory/order-packaging')->assertOk()->json('data.lists.to_go.lines.0');
    expect($line['quantity'])->toBe('50.000')->and($line['entered_unit'])->toBeNull()->and($line['pack_uuid'])->toBeNull();
    $audits = DB::table('pos_audit_logs')->where('event', 'inventory.order_packaging_updated')->count();
    pkb1List('to_go', [['type' => 'product', 'product_uuid' => $napkin->uuid, 'quantity' => '50']])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'inventory.order_packaging_updated')->count())->toBe($audits)
        ->and((float) DB::table('pos_order_packaging_lines')->whereNull('deleted_at')->value('quantity'))->toBe(50.0);
});

it('M2 refuses deleting a listed product from the catalogue, a physical item and an ingredient, in English and Arabic', function (): void {
    $ctx = makeMerchantActor();
    $water = pkProduct($ctx['company'], 'Water bottle', 'unit', ['cost_price' => '0.100']);
    $bag = pkItem($ctx['company'], 'Bag');
    $sugar = pkIngredient($ctx['company'], 'Sugar', 'g');
    pkb1List('delivery', [
        ['type' => 'product', 'product_uuid' => $water->uuid, 'quantity' => '1'],
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1'],
        ['type' => 'ingredient', 'ingredient_uuid' => $sugar->uuid, 'quantity' => '10'],
    ])->assertOk();

    foreach (["/api/products/{$water->uuid}", "/api/physical-items/{$bag->uuid}", "/api/ingredients/{$sugar->uuid}"] as $url) {
        $response = $this->deleteJson($url)->assertStatus(422)->assertJsonPath('code', 'on_packaging_list')->assertJsonPath('order_types', ['delivery']);
        expect($response->json('message'))->toContain('Remove it from the Order packaging list first')->toContain('Delivery')
            ->and($response->json('message_ar'))->toContain('أزله من قائمة تغليف الطلب أولاً')->toContain('توصيل');
    }
    expect(DB::table('pos_products')->whereIn('id', [$water->id, $bag->id])->whereNotNull('deleted_at')->count())->toBe(0)
        ->and(DB::table('pos_ingredients')->where('id', $sugar->id)->value('deleted_at'))->toBeNull();

    // An item deleted before this fix shows as not taken on the list.
    DB::table('pos_products')->where('id', $water->id)->update(['deleted_at' => now()]);
    $lines = collect($this->getJson('/api/inventory/order-packaging')->assertOk()->json('data.lists.delivery.lines'))->keyBy('name');
    expect($lines['Water bottle']['available'])->toBeFalse()->and($lines['Bag']['available'])->toBeTrue();
});

it('M3 refuses making a listed item branch-use, inactive or not counted in pieces', function (): void {
    $ctx = makeMerchantActor();
    $bag = pkItem($ctx['company'], 'Bag');
    $water = pkProduct($ctx['company'], 'Water bottle', 'unit');
    $sugar = pkIngredient($ctx['company'], 'Sugar', 'g');
    pkb1List('to_go', [
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1'],
        ['type' => 'product', 'product_uuid' => $water->uuid, 'quantity' => '1'],
        ['type' => 'ingredient', 'ingredient_uuid' => $sugar->uuid, 'quantity' => '10'],
    ])->assertOk();

    $refused = [
        $this->patchJson("/api/physical-items/{$bag->uuid}", ['purpose' => 'general']),
        $this->patchJson("/api/physical-items/{$bag->uuid}", ['status' => 'inactive']),
        $this->patchJson("/api/products/{$water->uuid}", ['stock_mode' => 'untracked']),
        $this->patchJson("/api/products/{$water->uuid}", ['status' => 'inactive']),
        $this->patchJson("/api/ingredients/{$sugar->uuid}", ['status' => 'inactive']),
    ];
    foreach ($refused as $i => $response) {
        $response->assertStatus(422);
        expect($response->json('code'))->toBe('on_packaging_list', "edit {$i}")
            ->and($response->json('message'))->toContain('Remove it from the Order packaging list first')
            ->and($response->json('message_ar'))->not->toBe('');
    }
    expect(DB::table('pos_products')->where('id', $bag->id)->value('internal_purpose'))->toBe('packaging')
        ->and(DB::table('pos_products')->where('id', $bag->id)->value('status'))->toBe('active')
        ->and(DB::table('pos_products')->where('id', $water->id)->value('stock_mode'))->toBe('unit')
        ->and(DB::table('pos_ingredients')->where('id', $sugar->id)->value('status'))->toBe('active');

    // Other edits of a listed item still go through.
    $this->patchJson("/api/physical-items/{$bag->uuid}", ['name' => 'Paper bag'])->assertOk();
    // And an inactive item cannot be put on a list.
    $bulb = pkItem($ctx['company'], 'Old bag');
    DB::table('pos_products')->where('id', $bulb->id)->update(['status' => 'inactive']);
    pkb1List('quick', [['type' => 'product', 'product_uuid' => $bulb->uuid, 'quantity' => '1']])->assertStatus(422)->assertJsonPath('code', 'packaging_inactive');
});

it('M4 lets the wizard merge split lines and switch to cooked in one save (recipe first, then the type)', function (): void {
    $ctx = makeMerchantActor();
    $burger = pkProduct($ctx['company'], 'Burger');
    $beef = pkIngredient($ctx['company'], 'Beef', 'g');
    $napkin = pkIngredient($ctx['company'], 'Napkin', 'piece');
    pkRecipeLine($burger, $beef, '150', null, 0);
    pkRecipeLine($burger, $napkin, '1', 1, 1);
    pkRecipeLine($burger, $napkin, '3', 12, 2);

    // The wizard's order for made to order → cooked: the merged recipe (all
    // types) while the product is still made to order, then the type.
    $this->putJson("/api/products/{$burger->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $beef->uuid, 'quantity' => '150', 'order_types' => 15],
        ['ingredient_uuid' => $napkin->uuid, 'quantity' => '2', 'order_types' => 15],
    ]])->assertOk();
    $this->patchJson("/api/products/{$burger->uuid}", ['stock_mode' => 'cooked'])->assertOk();
    expect(DB::table('pos_products')->where('id', $burger->id)->value('stock_mode'))->toBe('cooked')
        ->and(pkRecipeMasks($burger))->toBe([$beef->id => [15], $napkin->id => [15]]);
});

it('L1 sets every recipe line to all order types on the switch to cooked, with a version row, and costs a cooked recipe whole', function (): void {
    $ctx = makeMerchantActor();
    $cake = pkProduct($ctx['company'], 'Cake', 'ingredient', ['base_price' => '2.000']);
    $flour = pkIngredient($ctx['company'], 'Flour', 'g', '0.001');
    $box = pkIngredient($ctx['company'], 'Cake box', 'piece', '0.300');
    pkRecipeLine($cake, $flour, '200', 1, 0);
    pkRecipeLine($cake, $box, '1', 14, 1);

    $this->patchJson("/api/products/{$cake->uuid}", ['stock_mode' => 'cooked'])->assertOk();

    expect(pkRecipeMasks($cake))->toBe([$flour->id => [15], $box->id => [15]]);
    $version = DB::table('pos_product_recipe_versions')->where('product_id', $cake->id)->orderByDesc('id')->first();
    expect($version)->not->toBeNull()
        ->and($version->note)->toBe('Cooked: every recipe line is used for every order type.')
        ->and(collect(json_decode((string) $version->recipe_json, true))->pluck('order_types')->all())->toBe([1, 14]);

    $row = collect($this->getJson('/api/reports/recipe-cost?date_from=2026-06-01&date_to=2026-06-30')->assertOk()->json('data.rows'))->firstWhere('product_id', $cake->id);
    expect($row['theoretical_by_type'])->toBeNull()->and($row['theoretical_cost'])->toBe('0.500');
});

it('L2 takes the per-company, per-type lock inside the save transaction before reading the list', function (): void {
    $ctx = makeMerchantActor();
    $bag = pkItem($ctx['company'], 'Bag');
    $log = new ArrayObject();
    app()->instance(OrderPackagingLock::class, new class($log) extends OrderPackagingLock
    {
        public function __construct(private ArrayObject $log) {}

        public function acquire(int $companyId, string $orderType): void
        {
            $this->log->append([$companyId, $orderType, DB::transactionLevel(), DB::table('pos_audit_logs')->where('event', 'inventory.order_packaging_updated')->count()]);
        }
    });

    pkb1List('to_go', [['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1']])->assertOk();
    pkb1List('to_go', [['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1']])->assertOk();
    pkb1List('to_go', [['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '2']])->assertOk();

    // Every save (the no-op too) locks first, inside its transaction, before it reads or writes.
    $calls = $log->getArrayCopy();
    expect(count($calls))->toBe(3)
        ->and(array_column($calls, 0))->toBe([$ctx['company']->id, $ctx['company']->id, $ctx['company']->id])
        ->and(array_column($calls, 1))->toBe(['to_go', 'to_go', 'to_go'])
        ->and(min(array_column($calls, 2)))->toBeGreaterThan(0)
        ->and(array_column($calls, 3))->toBe([0, 1, 1])
        ->and(OrderPackagingLock::key(7, 'to_go'))->not->toBe(OrderPackagingLock::key(7, 'delivery'))
        ->and(OrderPackagingLock::key(7, 'to_go'))->not->toBe(OrderPackagingLock::key(8, 'to_go'));
    // The last save wins.
    expect(DB::table('pos_order_packaging_lines')->whereNull('deleted_at')->pluck('quantity')->map(fn ($q) => (float) $q)->all())->toBe([2.0]);
});

it('L3 answers packaging refusals in English and Arabic', function (): void {
    $ctx = makeMerchantActor();
    $prep = pkIngredient($ctx['company'], 'Syrup', 'ml', '0', ['is_prep' => true, 'prep_yield_quantity' => '1000']);
    $bag = pkItem($ctx['company'], 'Bag');

    $prepRefusal = pkb1List('quick', [['type' => 'ingredient', 'ingredient_uuid' => $prep->uuid, 'quantity' => '10']])->assertStatus(422)->assertJsonPath('code', 'packaging_prep');
    expect($prepRefusal->json('message_ar'))->toContain('Syrup');
    $duplicate = pkb1List('quick', [
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '1'],
        ['type' => 'product', 'product_uuid' => $bag->uuid, 'quantity' => '2'],
    ])->assertStatus(422)->assertJsonPath('code', 'packaging_duplicate');
    expect($duplicate->json('message_ar'))->toContain('مرتين');
    $tooSmall = pkb1List('quick', [['type' => 'ingredient', 'ingredient_uuid' => pkIngredient($ctx['company'], 'Salt', 'kg')->uuid, 'quantity' => '0.01', 'unit' => 'g']])->assertStatus(422)->assertJsonPath('code', 'packaging_unit');
    expect($tooSmall->json('message_ar'))->toContain('Salt');
});

it('L4 gives a user who may edit the lists (catalogue view + Edit recipes, no inventory view) working item pickers', function (): void {
    $chef = pkActorWith([MerchantPermission::CatalogueView->value, MerchantPermission::CatalogueRecipesManage->value]);
    $napkin = pkItem($chef['company'], 'Napkin');
    $pack = pkb1Pack($napkin, '50');
    $water = pkProduct($chef['company'], 'Water bottle', 'unit');
    pkItem($chef['company'], 'Bulb', '0.500', 'general');
    pkProduct($chef['company'], 'Latte');

    $this->getJson('/api/physical-items')->assertForbidden();
    $this->getJson('/api/ingredients')->assertOk();
    $data = $this->getJson('/api/inventory/order-packaging')->assertOk()->assertJsonPath('data.can_edit', true)->json('data.items');
    $items = collect($data)->keyBy('name');
    expect($items->keys()->sort()->values()->all())->toBe(['Napkin', 'Water bottle'])
        ->and($items['Napkin']['kind'])->toBe('physical')
        ->and($items['Water bottle']['kind'])->toBe('bought_in')
        ->and($items['Napkin']['packs'][0]['uuid'])->toBe($pack->uuid)
        ->and($items['Napkin']['packs'][0]['token'])->toBe(pkb1Token($pack->uuid));

    pkb1List('to_go', [['type' => 'product', 'product_uuid' => $water->uuid, 'quantity' => '1']])->assertOk();
});
