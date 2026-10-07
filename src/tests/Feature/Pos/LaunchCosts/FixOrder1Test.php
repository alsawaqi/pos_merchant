<?php

declare(strict_types=1);

/**
 * LAUNCH costs & allergens, Part A — fix order 1 (LAUNCH-COSTS_A_FIX_ORDER_1.md).
 *
 *   K-1  the merchant's own allergen ticks are kept exactly as given, even
 *        while the recipe brings the same allergen
 *   K-2  food cost includes food components (a cooked patty) at their own
 *        cost, never packaging; a component's ingredient price rise lists
 *        the dish
 *   K-3  price alerts and the dashboard stay within a fixed number of
 *        queries and a time budget with 200 alerts and 500 recipes
 *   K-4  "Dishes over target" counts only dishes on sale today
 *   K-5  a double "mark seen" (two managers at once) is 200, written once
 */

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('K-1 keeps a hand tick when the recipe brings the same allergen, and after the recipe drops it again', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $bun = p3Ingredient($c, 'Bun', 'piece', '0.050000');
    $cheese = p3Ingredient($c, 'Cheese', 'g', '0.006000');
    $sesameBun = p3Ingredient($c, 'Sesame bun', 'piece', '0.060000');
    lcTag($cheese, 'milk');
    lcTag($sesameBun, 'gluten', 'sesame');
    $burger = p4Product($c, 'Burger', '2.000', ['stock_mode' => 'ingredient']);
    lcRecipe($burger, [[$bun, '1']]);
    $url = '/api/products/'.$burger->uuid.'/allergens';

    // 1. The merchant ticks "contains milk" and "may contain sesame" by hand.
    $this->putJson($url, ['contains' => ['milk'], 'may_contain' => ['sesame']])->assertOk();
    // 2. The recipe later brings milk (cheese) and sesame (a sesame bun).
    $this->putJson('/api/products/'.$burger->uuid.'/recipe', ['lines' => [
        ['ingredient_uuid' => $sesameBun->uuid, 'quantity' => '1'], ['ingredient_uuid' => $cheese->uuid, 'quantity' => '20'],
    ]])->assertOk();
    // 3. The product page saves the own ticks as they are (another tick added).
    $saved = $this->putJson($url, ['contains' => ['milk', 'soy'], 'may_contain' => ['sesame']])->assertOk()->json('data');
    expect([$saved['own_contains'], $saved['own_may_contain']])->toBe([['soy', 'milk'], ['sesame']])
        ->and($saved['may_contain'])->toBe([]); // shown as contained today
    // 4. The recipe changes again: no cheese, a plain bun. The hand ticks are still there.
    $this->putJson('/api/products/'.$burger->uuid.'/recipe', ['lines' => [['ingredient_uuid' => $bun->uuid, 'quantity' => '1']]])->assertOk();
    $now = $this->getJson($url)->assertOk()->json('data');
    expect($now['contains'])->toBe(['soy', 'milk'])
        ->and($now['may_contain'])->toBe(['sesame'])
        ->and(DB::table('pos_product_allergens')->where('product_id', $burger->id)->pluck('kind', 'allergen')->all())
        ->toEqual(['milk' => 'contains', 'soy' => 'contains', 'sesame' => 'may_contain']);

    // A price-only save never touches the ticks.
    $this->patchJson('/api/products/'.$burger->uuid, ['base_price' => '2.250'])->assertOk();
    expect($this->getJson($url)->json('data.own_contains'))->toBe(['soy', 'milk']);
});

it('K-2 costs a food component (a cooked patty) at its own cost, never packaging, and lists the dish when its ingredient price moves', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $bun = p3Ingredient($c, 'Bun', 'piece', '0');
    $beef = p3Ingredient($c, 'Beef', 'g', '0');
    $supplier = lcSupplier($c, 'Butcher');
    lcReceive([[$bun, '100', '5.000'], [$beef, '10000', '40.000']], $supplier, now()->subDays(3)->toDateString()); // 0.050 / piece, 0.004 / g
    $patty = p4Product($c, 'Beef patty', '1.000', ['stock_mode' => 'cooked']);
    lcRecipe($patty, [[$beef, '150']]); // 0.600 a piece
    $box = p4Product($c, 'Burger box', '0.100', ['is_internal' => true, 'internal_purpose' => 'packaging', 'stock_mode' => 'unit', 'cost_price' => '0.100']);
    $sauce = p4Product($c, 'Sauce cup', '0.200', ['stock_mode' => 'unit', 'cost_price' => '0.030']);
    $burger = p4Product($c, 'Burger', '2.000', ['stock_mode' => 'ingredient']);
    lcRecipe($burger, [[$bun, '1']]);
    foreach ([[$patty, '1'], [$box, '1'], [$sauce, '1']] as [$component, $qty]) {
        DB::table('pos_product_components')->insert(['product_id' => $burger->id, 'component_product_id' => $component->id, 'quantity' => $qty, 'created_at' => now(), 'updated_at' => now()]);
    }

    // bun 0.050 + patty 0.600 + sauce cup 0.030 (the box is packaging) = 0.680 → 34% (over 30).
    $row = collect($this->getJson('/api/food-costs')->assertOk()->json('data'))->firstWhere('name', 'Burger');
    expect([$row['cost_baisas'], $row['food_cost_pct'], $row['over_target']])->toEqual([680, 34.0, true]);

    // Beef goes up 25%: the alert lists the patty AND the burger (at the new price: 0.050 + 0.750 + 0.030 = 0.830 → 41.5%).
    $receipt = lcReceive([[$beef, '10000', '50.000']], $supplier);
    $dishes = collect($receipt['price_alerts'][0]['dishes'])->keyBy('name');
    expect($dishes->keys()->sort()->values()->all())->toBe(['Beef patty', 'Burger'])
        ->and([$dishes['Burger']['cost_baisas'], $dishes['Burger']['food_cost_pct'], $dishes['Burger']['previous_food_cost_pct']])->toEqual([830, 41.5, 34.0]);
});

it('K-3 lists 200 alerts over 500 recipes and builds the dashboard within a fixed number of queries', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $receipt = (int) DB::table('pos_purchase_receipts')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $c->id,
        'received_at' => now()->subDays(25), 'created_at' => now(), 'updated_at' => now()]);
    $ingredients = [];
    for ($i = 0; $i < 20; $i++) {
        $ingredients[] = $ingredient = p3Ingredient($c, 'Ingredient '.$i, 'g', '0.001000');
        // 11 purchases each, every one 20% away from the previous: 10 alerts × 20 = 200.
        for ($n = 0; $n < 11; $n++) {
            $rid = (int) DB::table('pos_purchase_receipts')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $c->id,
                'received_at' => now()->subDays(25 - $n), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pos_purchase_receipt_lines')->insert(['purchase_receipt_id' => $rid, 'item_type' => 'ingredient', 'ingredient_id' => $ingredient->id,
                'item_name' => 'x', 'quantity' => '1000', 'line_cost' => '1', 'unit_cost' => $n % 2 === 0 ? '0.001000' : '0.001200',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $rows = [];
    for ($p = 0; $p < 500; $p++) {
        $rows[] = ['uuid' => (string) Str::uuid(), 'company_id' => $c->id, 'name' => 'Dish '.$p, 'base_price' => '1.000',
            'stock_mode' => 'ingredient', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('pos_products')->insert($chunk);
    }
    $recipe = [];
    foreach (DB::table('pos_products')->where('company_id', $c->id)->pluck('id') as $k => $productId) {
        foreach ([$ingredients[$k % 20], $ingredients[($k + 7) % 20]] as $j => $ingredient) {
            $recipe[] = ['product_id' => $productId, 'ingredient_id' => $ingredient->id, 'quantity' => '100', 'unit_at_set' => 'g',
                'sort_order' => $j, 'order_types' => 15, 'created_at' => now(), 'updated_at' => now()];
        }
    }
    foreach (array_chunk($recipe, 200) as $chunk) {
        DB::table('pos_product_recipes')->insert($chunk);
    }
    unset($receipt);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $started = microtime(true);
    $alerts = $this->getJson('/api/price-alerts')->assertOk()->json('data');
    $alertQueries = $queries;
    $alertSeconds = microtime(true) - $started;
    expect($alerts)->toHaveCount(200)
        ->and(count($alerts[0]['dishes']))->toBe(50);

    $queries = 0;
    $started = microtime(true);
    $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.price_alerts.count', 200);
    $dashboardQueries = $queries;
    $dashboardSeconds = microtime(true) - $started;
    fwrite(STDERR, sprintf("K-3 alerts: %d queries %.2fs; dashboard: %d queries %.2fs\n", $alertQueries, $alertSeconds, $dashboardQueries, $dashboardSeconds));

    expect($alertQueries)->toBeLessThanOrEqual(40)
        ->and($dashboardQueries)->toBeLessThanOrEqual(80)
        ->and($alertSeconds)->toBeLessThan(1.5); // base 33f2741: 3.75 s (the dish map was rebuilt per alert); fixed: 0.31 s
});

it('K-4 counts only dishes on sale today as over target; the list shows the others with their status', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $beef = p3Ingredient($c, 'Beef', 'g', '0.004000');
    foreach ([['On sale', []], ['Inactive', ['status' => 'inactive']], ['Ended', ['on_sale_until' => now('Asia/Muscat')->subDay()->toDateString()]],
        ['Not yet', ['on_sale_from' => now('Asia/Muscat')->addDay()->toDateString()]]] as [$name, $extra]) {
        lcRecipe(p4Product($c, $name, '1.000', ['stock_mode' => 'ingredient'] + $extra), [[$beef, '150']]); // 60% — over
    }

    expect($this->getJson('/api/dashboard/summary')->assertOk()->json('data.dishes_over_target'))
        ->toEqual(['count' => 1, 'costed' => 1, 'incomplete' => 0, 'no_recipe' => 0, 'target_percent' => 30]);
    $rows = collect($this->getJson('/api/food-costs?over=1')->assertOk()->json('data'))->keyBy('name');
    expect($rows->keys()->sort()->values()->all())->toBe(['Ended', 'Inactive', 'Not yet', 'On sale'])
        ->and($rows->map(static fn (array $r): array => [$r['product_status'], $r['on_sale']])->all())->toEqual([
            'On sale' => ['active', true], 'Inactive' => ['inactive', false], 'Ended' => ['active', false], 'Not yet' => ['active', false],
        ]);
});

it('K-5 answers 200 when another manager marks the same alert seen at the same moment, and writes it once', function (): void {
    $ctx = makeMerchantActor();
    $c = $ctx['company'];
    $tomato = p3Ingredient($c, 'Tomato', 'g', '0');
    lcReceive([[$tomato, '1000', '0.500']], null, now()->subDays(2)->toDateString());
    $lineId = lcReceive([[$tomato, '1000', '0.800']])['price_alerts'][0]['line_id'];

    // The other manager's request writes its review right after this
    // request's first touch of the reviews table (a real race, replayed in
    // order: a check-then-insert would then hit the UNIQUE line → 500).
    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced, $c, $lineId): void {
        if (! $raced && str_contains($query->sql, 'pos_price_alert_reviews')) {
            $raced = true;
            DB::table('pos_price_alert_reviews')->insertOrIgnore(['company_id' => $c->id, 'purchase_receipt_line_id' => $lineId,
                'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    $marked = $this->postJson('/api/price-alerts/'.$lineId.'/seen')->assertOk()->assertJsonPath('data.seen', true)->json('data.marked_now');
    expect($raced)->toBeTrue()
        ->and(DB::table('pos_price_alert_reviews')->count())->toBe(1)
        ->and(DB::table('pos_audit_logs')->where('event', 'inventory.price_alert.seen')->count())->toBe($marked ? 1 : 0);
    // And a plain second press is 200 too, written nothing.
    $this->postJson('/api/price-alerts/'.$lineId.'/seen')->assertOk()->assertJsonPath('data.marked_now', false);
    expect(DB::table('pos_price_alert_reviews')->count())->toBe(1);
});
