<?php

declare(strict_types=1);

/**
 * LAUNCH costs & allergens add-on, Part A (LAUNCH-COSTS_ALLERGENS_WORK_ORDER.md,
 * tester call 3): the merchant ticks allergens on ingredients (prep items
 * too, physical items where relevant); a dish's allergens are worked out
 * from its recipe (prep items followed down every level), its components
 * and, for a combo, every item incl. the choices; "may contain" is added by
 * hand; a worked-out allergen cannot be removed; a change reaches devices
 * (the products above it move); every write is audited and per merchant.
 */

use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/**
 * Bread → gluten; Mayo (prep) = egg yolk (eggs) + oil, ticked mustard on the
 * prep itself; Burger sauce (prep of a prep) = mayo + pickle; Burger = bun
 * (bread) + beef + burger sauce; Cup (physical item) contains nothing;
 * Milkshake has a component "Whipped topping" (physical item, milk);
 * Combo "Burger box" = Burger (upgrade Cheese burger: + cheese → milk) +
 * a drink (Cola, Milkshake; Peanut shake unticked).
 *
 * @return array<string, mixed>
 */
function lcKitchen(array $ctx): array
{
    $c = $ctx['company'];
    $k = [
        'bread' => p3Ingredient($c, 'Bun', 'piece', '0.050000'),
        'beef' => p3Ingredient($c, 'Beef', 'g', '0.004000'),
        'yolk' => p3Ingredient($c, 'Egg yolk', 'g', '0.003000'),
        'oil' => p3Ingredient($c, 'Oil', 'ml', '0.001000'),
        'pickle' => p3Ingredient($c, 'Pickle', 'g', '0.002000'),
        'cheese' => p3Ingredient($c, 'Cheese', 'g', '0.006000'),
        'peanut' => p3Ingredient($c, 'Peanut butter', 'g', '0.005000'),
    ];
    $k['mayo'] = p3Prep($c, 'Mayo', 'ml', '1000', [[$k['yolk'], '100'], [$k['oil'], '900']]);
    $k['sauce'] = p3Prep($c, 'Burger sauce', 'ml', '1000', [[$k['mayo'], '800'], [$k['pickle'], '200']]);
    lcTag($k['bread'], 'gluten');
    lcTag($k['yolk'], 'eggs');
    lcTag($k['mayo'], 'mustard');
    lcTag($k['cheese'], 'milk');
    lcTag($k['peanut'], 'peanuts');

    $drinks = lcCategory($c, 'Drinks');
    $k['burger'] = p4Product($c, 'Burger', '2.000', ['stock_mode' => 'ingredient']);
    lcRecipe($k['burger'], [[$k['bread'], '1'], [$k['beef'], '150'], [$k['sauce'], '20']]);
    $k['cheeseburger'] = p4Product($c, 'Cheese burger', '2.500', ['stock_mode' => 'ingredient']);
    lcRecipe($k['cheeseburger'], [[$k['bread'], '1'], [$k['beef'], '150'], [$k['cheese'], '20']]);
    $k['cola'] = p4Product($c, 'Cola', '0.500', ['category_id' => $drinks]);
    $k['shake'] = p4Product($c, 'Milkshake', '1.200', ['category_id' => $drinks]);
    $k['topping'] = p4Product($c, 'Whipped topping', '0.100', ['is_internal' => true, 'stock_mode' => 'unit', 'internal_purpose' => 'general']);
    lcOwn($k['topping'], 'contains', 'milk');
    DB::table('pos_product_components')->insert(['product_id' => $k['shake']->id, 'component_product_id' => $k['topping']->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $k['peanutShake'] = p4Product($c, 'Peanut shake', '1.500', ['category_id' => $drinks]);
    lcRecipe($k['peanutShake'], [[$k['peanut'], '30']]);
    $k['box'] = p4Product($c, 'Burger box', '3.000', ['product_type' => 'combo']);
    lcLine($c, ['combo_product_id' => $k['box']->id], ['kind' => 'fixed', 'product_id' => $k['burger']->id, 'quantity' => 1], [$k['cheeseburger']->id => '0.500']);
    lcLine($c, ['combo_product_id' => $k['box']->id], ['kind' => 'choice', 'category_id' => $drinks, 'pick_count' => 1, 'name' => 'Drink', 'name_ar' => 'المشروب'], [], [$k['peanutShake']->id]);

    return $k;
}

it('ticks allergens on an ingredient and a prep item, and works out a dish from its recipe every level down', function (): void {
    $ctx = makeMerchantActor();
    $k = lcKitchen($ctx);

    $this->putJson('/api/ingredients/'.$k['pickle']->uuid.'/allergens', ['allergens' => ['sulphites', 'mustard', 'sulphites']])
        ->assertOk()->assertJsonPath('data.allergens', ['mustard', 'sulphites']);
    // A prep item's own ticks plus everything its recipe brings.
    $this->putJson('/api/ingredients/'.$k['sauce']->uuid.'/allergens', ['allergens' => ['celery']])
        ->assertOk()->assertJsonPath('data.allergens', ['celery'])
        ->assertJsonPath('data.allergens_all', ['eggs', 'celery', 'mustard', 'sulphites']);

    $burger = $this->getJson('/api/products/'.$k['burger']->uuid.'/allergens')->assertOk()->json('data');
    expect($burger['contains'])->toBe(['gluten', 'eggs', 'celery', 'mustard', 'sulphites'])
        ->and($burger['derived'])->toBe(['gluten', 'eggs', 'celery', 'mustard', 'sulphites'])
        ->and($burger['may_contain'])->toBe([]);

    // The ingredient list shows the ticks (and a prep item, with its recipe's).
    $list = collect($this->getJson('/api/ingredients?include_prep=1')->assertOk()->json('data'))->keyBy('uuid');
    expect($list[$k['bread']->uuid]['allergens'])->toBe(['gluten'])
        ->and($list[$k['mayo']->uuid]['allergens'])->toBe(['mustard'])
        ->and($list[$k['mayo']->uuid]['allergens_all'])->toBe(['eggs', 'mustard']);
    $prep = $this->getJson('/api/prep-items/'.$k['mayo']->uuid)->assertOk()->json('data');
    expect($prep['allergens'])->toBe(['mustard'])->and($prep['allergens_all'])->toBe(['eggs', 'mustard']);

    // Audited, old and new.
    $audit = DB::table('pos_audit_logs')->where('event', 'catalogue.prep_item.allergens_updated')->sole();
    expect(json_decode((string) $audit->old_values, true))->toBe(['allergens' => []])
        ->and(json_decode((string) $audit->new_values, true))->toBe(['allergens' => ['celery']])
        ->and((int) $audit->auditable_id)->toBe($k['sauce']->id);
    expect(DB::table('pos_audit_logs')->where('event', 'inventory.ingredient.allergens_updated')->count())->toBe(1);
    // Saving the same ticks again writes nothing.
    $this->putJson('/api/ingredients/'.$k['pickle']->uuid.'/allergens', ['allergens' => ['mustard', 'sulphites']])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'inventory.ingredient.allergens_updated')->count())->toBe(1);
});

it('adds "may contain" by hand, never removes a worked-out allergen, and shows it all on the product page', function (): void {
    $ctx = makeMerchantActor();
    $k = lcKitchen($ctx);

    // Gluten is worked out (the bun): ticking it as "may contain" is dropped;
    // sesame is added; an own "contains" tick (soy) adds to the worked-out ones.
    $data = $this->putJson('/api/products/'.$k['burger']->uuid.'/allergens', ['contains' => ['soy'], 'may_contain' => ['gluten', 'sesame']])
        ->assertOk()->json('data');
    expect($data)->toBe([
        'contains' => ['gluten', 'eggs', 'soy', 'mustard'],
        'may_contain' => ['sesame'],
        'derived' => ['gluten', 'eggs', 'mustard'],
        'own_contains' => ['soy'],
        // Fix order 1 (K-1) — the hand ticks are kept as given (gluten is shown as contained).
        'own_may_contain' => ['gluten', 'sesame'],
    ]);
    // Unticking everything by hand leaves what the recipe brings.
    $data = $this->putJson('/api/products/'.$k['burger']->uuid.'/allergens', ['contains' => [], 'may_contain' => []])->assertOk()->json('data');
    expect($data['contains'])->toBe(['gluten', 'eggs', 'mustard'])->and($data['may_contain'])->toBe([]);
    expect(DB::table('pos_audit_logs')->where('event', 'catalogue.product.allergens_updated')->count())->toBe(2);

    // The product page (wizard read) and the list carry the same block.
    $page = $this->getJson('/api/products/'.$k['burger']->uuid)->assertOk()->json('data.allergens');
    expect($page['contains'])->toBe(['gluten', 'eggs', 'mustard']);
    $row = collect($this->getJson('/api/products')->assertOk()->json('data'))->firstWhere('uuid', $k['box']->uuid);
    // Combo: the burger, its upgrade (cheese) and every drink offered (the
    // topping on the milkshake) — never the unticked peanut shake.
    expect($row['allergens']['contains'])->toBe(['gluten', 'eggs', 'milk', 'mustard']);

    // Unknown codes are refused.
    $this->putJson('/api/products/'.$k['burger']->uuid.'/allergens', ['contains' => ['nuts'], 'may_contain' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('contains.0');
    $this->putJson('/api/ingredients/'.$k['bread']->uuid.'/allergens', ['allergens' => ['wheat']])
        ->assertUnprocessable()->assertJsonValidationErrors('allergens.0');
    // The fixed list, English and Arabic.
    $codes = $this->getJson('/api/allergens')->assertOk()->json('data');
    expect(count($codes))->toBe(14)->and($codes[0])->toBe(['code' => 'gluten', 'name' => 'Gluten', 'name_ar' => 'الغلوتين']);
});

it('moves every product and option above a changed ingredient so devices pull the new allergens', function (): void {
    $ctx = makeMerchantActor();
    $k = lcKitchen($ctx);
    // The shake's whipped topping is a physical item ticked by hand; a cookie
    // is a component of nothing; an "Extra pickle" option uses the pickle.
    $group = (int) DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id,
        'name' => 'Extras', 'selection_mode' => 'multi', 'status' => 'active', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    $option = (int) DB::table('pos_addons')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id,
        'add_on_group_id' => $group, 'name' => 'Extra pickle', 'price_delta' => '0.100', 'status' => 'active', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    DB::table('pos_addon_consumptions')->insert(['add_on_id' => $option, 'ingredient_id' => $k['pickle']->id, 'direction' => 'add',
        'quantity' => 10, 'unit' => 'g', 'created_at' => now(), 'updated_at' => now()]);
    $cookie = p4Product($ctx['company'], 'Cookie', '0.500', ['stock_mode' => 'unit']);
    DB::table('pos_products')->update(['updated_at' => now()->subDay()]);
    $old = now()->subDay()->toDateTimeString();

    // The pickle sits two prep levels under the burger.
    $this->putJson('/api/ingredients/'.$k['pickle']->uuid.'/allergens', ['allergens' => ['mustard']])->assertOk();
    $moved = fn (int $id): bool => (string) DB::table('pos_products')->where('id', $id)->value('updated_at') > $old;
    expect($moved($k['burger']->id))->toBeTrue()
        ->and($moved($k['cheeseburger']->id))->toBeFalse()
        ->and($moved($cookie->id))->toBeFalse()
        ->and((string) DB::table('pos_addons')->where('id', $option)->value('updated_at') > $old)->toBeTrue()
        ->and((string) DB::table('pos_addon_groups')->where('id', $group)->value('updated_at') > $old)->toBeTrue();

    // A physical item's tick moves the products it is a component of.
    DB::table('pos_products')->update(['updated_at' => now()->subDay()]);
    $this->putJson('/api/products/'.$k['topping']->uuid.'/allergens', ['contains' => ['milk', 'soy'], 'may_contain' => []])->assertOk();
    expect($moved($k['shake']->id))->toBeTrue()->and($moved($k['topping']->id))->toBeTrue()->and($moved($k['cola']->id))->toBeFalse();
    $physical = collect($this->getJson('/api/physical-items')->assertOk()->json('data'))->firstWhere('uuid', $k['topping']->uuid);
    expect($physical['allergens'])->toBe(['soy', 'milk']);

    // A recipe saved in the portal moves what uses the product as a component.
    DB::table('pos_product_components')->insert(['product_id' => $cookie->id, 'component_product_id' => $k['cheeseburger']->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_products')->update(['updated_at' => now()->subDay()]);
    $this->putJson('/api/products/'.$k['cheeseburger']->uuid.'/recipe', ['lines' => [
        ['ingredient_uuid' => $k['bread']->uuid, 'quantity' => '1'], ['ingredient_uuid' => $k['cheese']->uuid, 'quantity' => '30'],
    ]])->assertOk();
    expect($moved($cookie->id))->toBeTrue();
});

it('keeps the ticks to the right permissions', function (): void {
    $ctx = makeMerchantActor();
    $k = lcKitchen($ctx);

    // A viewer reads but never ticks.
    lcActAs($ctx, [MerchantPermission::CatalogueView->value, MerchantPermission::InventoryView->value]);
    $this->getJson('/api/products/'.$k['burger']->uuid.'/allergens')->assertOk();
    $this->getJson('/api/allergens')->assertOk();
    $this->putJson('/api/ingredients/'.$k['bread']->uuid.'/allergens', ['allergens' => ['gluten', 'sesame']])->assertForbidden();
    $this->putJson('/api/products/'.$k['burger']->uuid.'/allergens', ['contains' => [], 'may_contain' => ['sesame']])->assertForbidden();

    // An ingredient needs inventory.manage; a prep item "Edit recipes".
    lcActAs($ctx, [MerchantPermission::InventoryView->value, MerchantPermission::InventoryManage->value]);
    $this->putJson('/api/ingredients/'.$k['bread']->uuid.'/allergens', ['allergens' => ['gluten', 'sesame']])->assertOk();
    $this->putJson('/api/ingredients/'.$k['mayo']->uuid.'/allergens', ['allergens' => []])->assertForbidden();
    // A physical item is inventory's; a menu product the catalogue's.
    $this->putJson('/api/products/'.$k['topping']->uuid.'/allergens', ['contains' => ['milk'], 'may_contain' => []])->assertOk();
    $this->putJson('/api/products/'.$k['burger']->uuid.'/allergens', ['contains' => [], 'may_contain' => ['sesame']])->assertForbidden();
    lcActAs($ctx, [MerchantPermission::CatalogueView->value, MerchantPermission::CatalogueRecipesManage->value, MerchantPermission::CatalogueManage->value]);
    $this->putJson('/api/ingredients/'.$k['mayo']->uuid.'/allergens', ['allergens' => []])->assertOk();
    $this->putJson('/api/products/'.$k['burger']->uuid.'/allergens', ['contains' => [], 'may_contain' => ['sesame']])->assertOk();
    $this->putJson('/api/products/'.$k['topping']->uuid.'/allergens', ['contains' => [], 'may_contain' => []])->assertForbidden();
});

it('never reads or writes another merchant\'s ingredients, products or ticks', function (): void {
    $other = makeMerchantActor();
    $theirBread = p3Ingredient($other['company'], 'Their bun', 'piece', '0.050000');
    lcTag($theirBread, 'gluten');
    $theirBurger = p4Product($other['company'], 'Their burger', '2.000', ['stock_mode' => 'ingredient']);

    $ctx = makeMerchantActor(MerchantRole::SuperAdmin->value);
    $k = lcKitchen($ctx);
    // Bad data: a line of ours naming their ingredient, a tick of theirs on our product.
    lcRecipe($k['cheeseburger'], [[$theirBread, '1']]);
    DB::table('pos_product_allergens')->insert(['company_id' => $other['company']->id, 'product_id' => $k['cola']->id, 'allergen' => 'fish', 'kind' => 'contains', 'created_at' => now(), 'updated_at' => now()]);

    $this->putJson('/api/ingredients/'.$theirBread->uuid.'/allergens', ['allergens' => []])->assertNotFound();
    $this->putJson('/api/products/'.$theirBurger->uuid.'/allergens', ['contains' => [], 'may_contain' => []])->assertNotFound();
    $this->getJson('/api/products/'.$theirBurger->uuid.'/allergens')->assertNotFound();
    expect(DB::table('pos_ingredient_allergens')->where('ingredient_id', $theirBread->id)->count())->toBe(1);
    expect($this->getJson('/api/products/'.$k['cheeseburger']->uuid.'/allergens')->json('data.contains'))->toBe(['gluten', 'milk'])
        ->and($this->getJson('/api/products/'.$k['cola']->uuid.'/allergens')->json('data.contains'))->toBe([]);
});
