<?php

declare(strict_types=1);

/**
 * LAUNCH packaging add-on, part B1 — the "Used for" ticks (work order §1.1,
 * §2.2–2.5, §3 Part B1).
 *
 * Every recipe line of a made-to-order product, every physical-item row and
 * every add-on stock line carries order_types (1 dine in, 2 quick, 4 to go,
 * 8 delivery; 15 = all, the default). A line with no tick is refused; the
 * same item may sit on several lines only when their ticks do not overlap
 * (napkin ×1 dine in, napkin ×3 to go + delivery). A line sent without ticks
 * (an old portal tab) keeps the stored ticks. A tick change is a real change:
 * a recipe version, the "Edit recipes" gate (recipe and add-on lines) or
 * catalogue.manage (physical items), the audit diff, and the product touched.
 * A cooked product's recipe lines are always for every type.
 * Before (81a59b4): no order_types anywhere; the same item twice was a 422.
 */

use App\Enums\MerchantPermission;
use App\Models\AddOn;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** A made-to-order latte: milk (all types), sugar (all types). */
function pkLatte(array $ctx): array
{
    $latte = pkProduct($ctx['company'], 'Latte');
    $milk = pkIngredient($ctx['company'], 'Milk', 'ml', '0.001');
    $napkin = pkIngredient($ctx['company'], 'Napkin', 'piece', '0.010');
    pkRecipeLine($latte, $milk, '200');

    return compact('latte', 'milk', 'napkin');
}

it('saves the same ingredient on two lines whose ticks do not overlap, with a version, an audit diff and a touch', function (): void {
    $ctx = makeMerchantActor();
    ['latte' => $latte, 'milk' => $milk, 'napkin' => $napkin] = pkLatte($ctx);

    $this->putJson("/api/products/{$latte->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $milk->uuid, 'quantity' => '200'],
        ['ingredient_uuid' => $napkin->uuid, 'quantity' => '1', 'order_types' => 1],
        ['ingredient_uuid' => $napkin->uuid, 'quantity' => '3', 'order_types' => 12],
    ]])->assertOk()
        ->assertJsonPath('data.recipe_lines.1.order_types', 1)
        ->assertJsonPath('data.recipe_lines.2.order_types', 12)
        ->assertJsonPath('data.recipe_lines.0.order_types', 15);

    expect(pkRecipeMasks($latte))->toBe([$milk->id => [15], $napkin->id => [1, 12]])
        ->and(DB::table('pos_product_recipes')->where('product_id', $latte->id)->where('ingredient_id', $napkin->id)->orderBy('sort_order')->pluck('quantity')->map(fn ($q) => (float) $q)->all())->toBe([1.0, 3.0])
        ->and(DB::table('pos_product_recipe_versions')->where('product_id', $latte->id)->count())->toBe(1)
        ->and(pkTouchedRecently($latte))->toBeTrue();

    $audit = pkAudit('catalogue.product.recipe_updated', $latte->id);
    expect($audit['new']['ingredient_ids'])->toBe([$milk->id, $napkin->id])
        ->and($audit['new']['lines'])->toBe(['Milk: 200 ml', 'Napkin: 1 piece [Dine in]', 'Napkin: 3 piece [To go, Delivery]'])
        ->and(collect($audit['new']['changes'])->pluck('change')->all())->toBe(['added', 'added']);

    // The history names the ticks of each line.
    $history = $this->getJson("/api/products/{$latte->uuid}/recipe-history")->assertOk();
    expect(collect($history->json('data.current.lines'))->pluck('order_types')->all())->toBe([null, 1, 12]);
});

it('refuses overlapping ticks on the same ingredient and a line with no tick', function (): void {
    $ctx = makeMerchantActor();
    ['latte' => $latte, 'milk' => $milk, 'napkin' => $napkin] = pkLatte($ctx);

    $overlap = $this->putJson("/api/products/{$latte->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $napkin->uuid, 'quantity' => '1', 'order_types' => 3],
        ['ingredient_uuid' => $napkin->uuid, 'quantity' => '3', 'order_types' => 6],
    ]])->assertStatus(422);
    expect($overlap->json('message'))->toContain('Napkin')->toContain('Quick order');

    $this->putJson("/api/products/{$latte->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $milk->uuid, 'quantity' => '200', 'order_types' => 0],
    ]])->assertStatus(422)->assertJsonValidationErrors(['lines.0.order_types']);
    $this->putJson("/api/products/{$latte->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $milk->uuid, 'quantity' => '200', 'order_types' => 16],
    ]])->assertStatus(422)->assertJsonValidationErrors(['lines.0.order_types']);

    expect(pkRecipeMasks($latte))->toBe([$milk->id => [15]])
        ->and(DB::table('pos_product_recipe_versions')->where('product_id', $latte->id)->count())->toBe(0);
});

it('keeps the stored ticks when an old tab saves a line without them', function (): void {
    $ctx = makeMerchantActor();
    $latte = pkProduct($ctx['company'], 'Latte');
    $milk = pkIngredient($ctx['company'], 'Milk', 'ml', '0.001');
    $sugar = pkIngredient($ctx['company'], 'Sugar', 'g', '0.001');
    pkRecipeLine($latte, $milk, '200', 4);
    pkRecipeLine($latte, $sugar, '5', null, 1);

    // The old tab changes the milk amount and sends no ticks at all.
    $this->putJson("/api/products/{$latte->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $milk->uuid, 'quantity' => '250'],
        ['ingredient_uuid' => $sugar->uuid, 'quantity' => '5'],
    ]])->assertOk();

    expect(pkRecipeMasks($latte))->toBe([$milk->id => [4], $sugar->id => [15]]);

    // Re-sending the untouched recipe (with or without ticks) is a no-op.
    $versions = DB::table('pos_product_recipe_versions')->where('product_id', $latte->id)->count();
    $this->putJson("/api/products/{$latte->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $milk->uuid, 'quantity' => '250', 'order_types' => 4],
        ['ingredient_uuid' => $sugar->uuid, 'quantity' => '5'],
    ]])->assertOk();
    expect(DB::table('pos_product_recipe_versions')->where('product_id', $latte->id)->count())->toBe($versions);
});

it('makes a tick change a real change: a version, the "Edit recipes" gate and a diff', function (): void {
    $ctx = makeMerchantActor();
    ['latte' => $latte, 'milk' => $milk] = pkLatte($ctx);

    $this->putJson("/api/products/{$latte->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $milk->uuid, 'quantity' => '200', 'order_types' => 6],
    ]])->assertOk();

    expect(pkRecipeMasks($latte))->toBe([$milk->id => [6]])
        ->and(DB::table('pos_product_recipe_versions')->where('product_id', $latte->id)->count())->toBe(1);
    $change = pkAudit('catalogue.product.recipe_updated', $latte->id)['new']['changes'][0];
    expect($change)->toMatchArray(['change' => 'changed', 'before' => '200 ml', 'after' => '200 ml', 'before_order_types' => 15, 'after_order_types' => 6]);
    $version = json_decode((string) DB::table('pos_product_recipe_versions')->where('product_id', $latte->id)->value('recipe_json'), true);
    // The snapshot of an untagged line stays as before (no order_types key).
    expect($version[0])->not->toHaveKey('order_types');

    // A catalogue manager without "Edit recipes" cannot change the ticks.
    $other = pkActorWith([MerchantPermission::CatalogueView->value, MerchantPermission::CatalogueManage->value]);
    $latte2 = pkProduct($other['company'], 'Latte');
    $milk2 = pkIngredient($other['company'], 'Milk', 'ml');
    pkRecipeLine($latte2, $milk2, '200');
    $this->putJson("/api/products/{$latte2->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $milk2->uuid, 'quantity' => '200', 'order_types' => 4],
    ]])->assertForbidden();
    expect(pkRecipeMasks($latte2))->toBe([$milk2->id => [15]]);
});

it('keeps a cooked product\'s recipe lines for every order type', function (): void {
    $ctx = makeMerchantActor();
    $patty = pkProduct($ctx['company'], 'Patty', 'cooked');
    $beef = pkIngredient($ctx['company'], 'Beef', 'g');

    $this->putJson("/api/products/{$patty->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $beef->uuid, 'quantity' => '150', 'order_types' => 4],
    ]])->assertStatus(422);
    $this->putJson("/api/products/{$patty->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $beef->uuid, 'quantity' => '150', 'order_types' => 15],
    ]])->assertOk();
    expect(pkRecipeMasks($patty))->toBe([$beef->id => [15]]);
});

it('ticks physical-item rows: same item on non-overlapping rows, overlap refused, catalogue.manage, audited, touched', function (): void {
    $ctx = makeMerchantActor();
    $latte = pkProduct($ctx['company'], 'Latte');
    $cup = pkItem($ctx['company'], 'Cup');
    $napkin = pkItem($ctx['company'], 'Napkin', '0.005');

    $this->putJson("/api/products/{$latte->uuid}/components", ['lines' => [
        ['component_uuid' => $cup->uuid, 'quantity' => '1', 'order_types' => 14],
        ['component_uuid' => $napkin->uuid, 'quantity' => '1', 'order_types' => 1],
        ['component_uuid' => $napkin->uuid, 'quantity' => '3', 'order_types' => 12],
    ]])->assertOk()
        ->assertJsonPath('data.component_lines.0.order_types', 14)
        ->assertJsonPath('data.component_lines.2.order_types', 12);

    $rows = DB::table('pos_product_components')->where('product_id', $latte->id)->orderBy('id')->get();
    expect($rows->map(fn ($r) => [(int) $r->component_product_id, (float) $r->quantity, (int) $r->order_types])->all())
        ->toBe([[$cup->id, 1.0, 14], [$napkin->id, 1.0, 1], [$napkin->id, 3.0, 12]])
        ->and(pkTouchedRecently($latte))->toBeTrue();
    $audit = pkAudit('catalogue.product.components_updated', $latte->id);
    expect($audit['new']['components'])->toBe([$cup->id.':14' => '1.000', $napkin->id.':1' => '1.000', $napkin->id.':12' => '3.000']);

    // Overlap → 422; no tick → 422; old tab (no ticks) keeps them.
    $this->putJson("/api/products/{$latte->uuid}/components", ['lines' => [
        ['component_uuid' => $napkin->uuid, 'quantity' => '1', 'order_types' => 9],
        ['component_uuid' => $napkin->uuid, 'quantity' => '3', 'order_types' => 12],
    ]])->assertStatus(422);
    $this->putJson("/api/products/{$latte->uuid}/components", ['lines' => [
        ['component_uuid' => $cup->uuid, 'quantity' => '1', 'order_types' => 0],
    ]])->assertStatus(422);
    $this->putJson("/api/products/{$latte->uuid}/components", ['lines' => [
        ['component_uuid' => $cup->uuid, 'quantity' => '2'],
    ]])->assertOk();
    expect(DB::table('pos_product_components')->where('product_id', $latte->id)->pluck('order_types')->map(fn ($m) => (int) $m)->all())->toBe([14]);

    // "Edit recipes" without catalogue.manage cannot change physical items.
    $chef = pkActorWith([MerchantPermission::CatalogueView->value, MerchantPermission::CatalogueRecipesManage->value]);
    $tea = pkProduct($chef['company'], 'Tea');
    $cup2 = pkItem($chef['company'], 'Cup');
    pkComponent($tea, $cup2, '1');
    $this->putJson("/api/products/{$tea->uuid}/components", ['lines' => [
        ['component_uuid' => $cup2->uuid, 'quantity' => '1', 'order_types' => 4],
    ]])->assertForbidden();
    expect((int) DB::table('pos_product_components')->where('product_id', $tea->id)->value('order_types'))->toBe(15);
});

it('ticks add-on stock lines: per direction, overlap refused, kept on an old tab, "Edit recipes", owner product touched', function (): void {
    $ctx = makeMerchantActor();
    $latte = pkProduct($ctx['company'], 'Latte');
    $cupS = pkItem($ctx['company'], 'Cup 8oz');
    $cupL = pkItem($ctx['company'], 'Cup 12oz');
    $milk = pkIngredient($ctx['company'], 'Milk', 'ml', '0.001');
    $group = $this->postJson("/api/products/{$latte->uuid}/addon-groups", ['name' => 'Size', 'selection_mode' => 'single'])->assertCreated()->json('data');
    $option = $this->postJson("/api/addon-groups/{$group['uuid']}/addons", ['name' => 'Large', 'price_delta' => '0.300'])->assertCreated()->json('data');
    DB::table('pos_products')->where('id', $latte->id)->update(['updated_at' => now()->subDay()]);

    // Large swaps cups only for to go / delivery; milk +50 ml for every type.
    $lines = [
        ['type' => 'product', 'product_uuid' => $cupL->uuid, 'direction' => 'add', 'quantity' => '1', 'order_types' => 12],
        ['type' => 'product', 'product_uuid' => $cupS->uuid, 'direction' => 'remove', 'quantity' => '1', 'order_types' => 12],
        ['type' => 'ingredient', 'ingredient_uuid' => $milk->uuid, 'direction' => 'add', 'quantity' => '50'],
        ['type' => 'ingredient', 'ingredient_uuid' => $milk->uuid, 'direction' => 'remove', 'quantity' => '10', 'order_types' => 1],
    ];
    $this->patchJson("/api/addons/{$option['uuid']}", ['consumption' => $lines])->assertOk()
        ->assertJsonPath('data.consumption.0.order_types', 12)
        ->assertJsonPath('data.consumption.2.order_types', 15);

    $addon = AddOn::query()->where('uuid', $option['uuid'])->firstOrFail();
    expect(DB::table('pos_addon_consumptions')->where('add_on_id', $addon->id)->orderBy('display_order')->pluck('order_types')->map(fn ($m) => (int) $m)->all())->toBe([12, 12, 15, 1])
        ->and(pkTouchedRecently($latte))->toBeTrue()
        ->and(pkAudit('catalogue.addon.consumption_updated', $addon->id)['new']['lines'])->toHaveKey('p:'.$cupL->id.':add:12');

    // The same item in the same direction twice with overlapping ticks → 422.
    $this->patchJson("/api/addons/{$option['uuid']}", ['consumption' => [
        ['type' => 'product', 'product_uuid' => $cupL->uuid, 'direction' => 'add', 'quantity' => '1', 'order_types' => 12],
        ['type' => 'product', 'product_uuid' => $cupL->uuid, 'direction' => 'add', 'quantity' => '2', 'order_types' => 8],
    ]])->assertStatus(422);
    // Non-overlapping → fine.
    $this->patchJson("/api/addons/{$option['uuid']}", ['consumption' => [
        ['type' => 'product', 'product_uuid' => $cupL->uuid, 'direction' => 'add', 'quantity' => '1', 'order_types' => 4],
        ['type' => 'product', 'product_uuid' => $cupL->uuid, 'direction' => 'add', 'quantity' => '2', 'order_types' => 8],
    ]])->assertOk();
    // An old tab (no ticks) keeps the stored ticks of an item's only line.
    $this->patchJson("/api/addons/{$option['uuid']}", ['consumption' => [
        ['type' => 'product', 'product_uuid' => $cupL->uuid, 'direction' => 'add', 'quantity' => '1', 'order_types' => 4],
    ]])->assertOk();
    $this->patchJson("/api/addons/{$option['uuid']}", ['consumption' => [
        ['type' => 'product', 'product_uuid' => $cupL->uuid, 'direction' => 'add', 'quantity' => '2'],
    ]])->assertOk();
    expect((int) DB::table('pos_addon_consumptions')->where('add_on_id', $addon->id)->value('order_types'))->toBe(4);

    // A tick change needs "Edit recipes".
    $manager = pkActorWith([MerchantPermission::CatalogueView->value, MerchantPermission::CatalogueManage->value]);
    $group2 = DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $manager['company']->id, 'name' => 'Size', 'selection_mode' => 'single', 'created_at' => now(), 'updated_at' => now()]);
    $addon2 = DB::table('pos_addons')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $manager['company']->id, 'add_on_group_id' => $group2, 'name' => 'Large', 'price_delta' => '0.300', 'created_at' => now(), 'updated_at' => now()]);
    $cup2 = pkItem($manager['company'], 'Cup');
    DB::table('pos_addon_consumptions')->insert(['add_on_id' => $addon2, 'component_product_id' => $cup2->id, 'direction' => 'add', 'quantity' => '1', 'created_at' => now(), 'updated_at' => now()]);
    $uuid2 = DB::table('pos_addons')->where('id', $addon2)->value('uuid');
    $this->patchJson("/api/addons/{$uuid2}", ['consumption' => [
        ['type' => 'product', 'product_uuid' => $cup2->uuid, 'direction' => 'add', 'quantity' => '1', 'order_types' => 4],
    ]])->assertForbidden();
});

it('creates a product in the wizard with ticked recipe lines and physical items', function (): void {
    $ctx = makeMerchantActor();
    $milk = pkIngredient($ctx['company'], 'Milk', 'ml');
    $sugar = pkIngredient($ctx['company'], 'Sugar sachet', 'piece');
    $cup = pkItem($ctx['company'], 'Cup');
    $category = DB::table('pos_product_categories')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'name' => 'Coffee', 'created_at' => now(), 'updated_at' => now()]);

    $response = $this->postJson('/api/products/wizard', [
        'product' => ['name' => 'Flat white', 'category_id' => $category, 'base_price' => '1.500', 'stock_mode' => 'ingredient'],
        'addon_group_uuids' => [],
        'owned_groups' => [],
        'recipe_lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '150'],
            ['ingredient_uuid' => $sugar->uuid, 'quantity' => '1', 'order_types' => 1],
            ['ingredient_uuid' => $sugar->uuid, 'quantity' => '2', 'order_types' => 12],
        ],
        'component_lines' => [['component_uuid' => $cup->uuid, 'quantity' => '1', 'order_types' => 14]],
        'branches' => null,
        'delivery_prices' => [],
    ])->assertCreated();

    $product = Product::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
    expect(pkRecipeMasks($product))->toBe([$milk->id => [15], $sugar->id => [1, 12]])
        ->and((int) DB::table('pos_product_components')->where('product_id', $product->id)->value('order_types'))->toBe(14);
});
