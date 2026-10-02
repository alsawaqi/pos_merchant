<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 P3-2 — recipe history you can see.
 *
 * pos_product_recipe_versions rows were saved but never shown, had no
 * version number, the note was never sent and the audit row hid quantity
 * changes (150 g → 120 g left no visible trace). Now the history lists each
 * change: version number, date, who, every line added / removed / changed
 * before → after in the entered unit, and the note; the audit row records
 * the quantity changes; prep items get the same history.
 */

use App\Models\Product;
use App\Models\ProductRecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('lists every change with version, date, who, before → after in the entered unit, and the note', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->forceFill(['name' => 'Chef Amal'])->save();
    $flour = p3Ingredient($ctx['company'], 'Flour', 'kg', '0.400');
    $sugar = p3Ingredient($ctx['company'], 'Sugar', 'g', '0.001');
    $honey = p3Ingredient($ctx['company'], 'Honey', 'g', '0.004');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient', 'name' => 'Cake']);

    $this->travelTo('2026-10-02 10:25:00');
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $flour->uuid, 'quantity' => '150', 'unit' => 'g'],
            ['ingredient_uuid' => $honey->uuid, 'quantity' => '10'],
        ],
        'note' => 'First recipe',
    ])->assertOk();

    $this->travelTo('2026-10-02 10:30:00');
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $flour->uuid, 'quantity' => '120', 'unit' => 'g'],
            ['ingredient_uuid' => $sugar->uuid, 'quantity' => '5'],
        ],
        'note' => 'Less flour, sugar instead of honey',
    ])->assertOk();
    $this->travelTo('2026-10-02 10:31:00');

    $history = $this->getJson("/api/products/{$product->uuid}/recipe-history")->assertOk()->json('data');

    expect($history['current']['version'])->toBe(2)
        ->and(array_column($history['current']['lines'], 'amount'))->toBe(['120 g', '5 g'])
        ->and($history['versions'])->toHaveCount(2);

    [$latest, $first] = $history['versions'];
    expect($latest['version'])->toBe(2)
        ->and($latest['edited_by']['name'])->toBe('Chef Amal')
        ->and($latest['edited_at'])->toStartWith('2026-10-02T10:30:00')
        ->and($latest['note'])->toBe('Less flour, sugar instead of honey')
        ->and($latest['changes'])->toBe([
            ['ingredient_id' => $flour->id, 'ingredient' => 'Flour', 'change' => 'changed', 'before' => '150 g', 'after' => '120 g', 'before_base' => '0.15 kg', 'after_base' => '0.12 kg'],
            ['ingredient_id' => $sugar->id, 'ingredient' => 'Sugar', 'change' => 'added', 'before' => null, 'after' => '5 g', 'before_base' => null, 'after_base' => '5 g'],
            ['ingredient_id' => $honey->id, 'ingredient' => 'Honey', 'change' => 'removed', 'before' => '10 g', 'after' => null, 'before_base' => '10 g', 'after_base' => null],
        ]);
    expect($first['version'])->toBe(1)
        ->and($first['note'])->toBe('First recipe')
        ->and(array_column($first['changes'], 'change'))->toBe(['added', 'added'])
        ->and(array_column($first['changes'], 'after'))->toBe(['150 g', '10 g']);
});

it('records the quantity changes and the note in the audit row', function (): void {
    $ctx = makeMerchantActor();
    $flour = p3Ingredient($ctx['company'], 'Flour', 'g', '0.0004');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => [['ingredient_uuid' => $flour->uuid, 'quantity' => '150']]])->assertOk();
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $flour->uuid, 'quantity' => '120']],
        'note' => 'Smaller portion',
    ])->assertOk();

    $audit = DB::table('pos_audit_logs')
        ->where('event', 'catalogue.product.recipe_updated')
        ->where('auditable_id', $product->id)
        ->orderByDesc('id')
        ->first();
    $new = json_decode($audit->new_values, true);
    $old = json_decode($audit->old_values, true);

    expect($old['lines'])->toBe([$flour->name.': 150 g'])
        ->and($new['lines'])->toBe([$flour->name.': 120 g'])
        ->and($new['note'])->toBe('Smaller portion')
        ->and($new['changes'][0])->toMatchArray(['change' => 'changed', 'before' => '150 g', 'after' => '120 g']);
});

it('keeps the version snapshot readable by pos_api: base quantity and unit, plus the entered form', function (): void {
    $ctx = makeMerchantActor();
    $saffron = p3Ingredient($ctx['company'], 'Saffron', 'kg', '1200.000');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => [['ingredient_uuid' => $saffron->uuid, 'quantity' => '5', 'unit' => 'g']]])->assertOk();
    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => []])->assertOk();

    // pos_api P3-6 reads: the recipe_json of the first version edited AFTER a
    // sale, else the current recipe; "[]" = no recipe then. One row per change,
    // each with edited_at, and per line ingredient_id + BASE quantity + unit.
    $rows = DB::table('pos_product_recipe_versions')->where('product_id', $product->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->recipe_json)->toBe('[]')
        ->and($rows[0]->edited_at)->not->toBeNull()
        ->and($rows[1]->edited_at)->not->toBeNull();

    $snapshot = ProductRecipeVersion::query()->where('product_id', $product->id)->orderByDesc('id')->firstOrFail()->recipe_json;
    expect($snapshot)->toHaveCount(1)
        ->and($snapshot[0])->toMatchArray([
            'ingredient_id' => $saffron->id,
            'quantity' => '0.005',
            'unit' => 'kg',
            'entered_unit' => 'g',
            'entered_quantity' => '5',
        ])
        ->and(json_decode((string) $rows[1]->recipe_json, true)[0])->toHaveKeys(['ingredient_id', 'quantity', 'unit']);
});

it('saves the note sent with a new product\'s recipe from the wizard', function (): void {
    $ctx = makeMerchantActor();
    $milk = p3Ingredient($ctx['company'], 'Milk', 'ml', '0.0005');

    $uuid = $this->postJson('/api/products/wizard', [
        'product' => ['name' => 'Latte', 'base_price' => '1.500', 'stock_mode' => 'ingredient'],
        'addon_group_uuids' => [],
        'owned_groups' => [],
        'recipe_lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '180']],
        'recipe_note' => 'Launch recipe',
        'component_lines' => [],
        'delivery_prices' => [],
    ])->assertCreated()->json('data.uuid');

    $history = $this->getJson("/api/products/{$uuid}/recipe-history")->assertOk()->json('data');
    expect($history['versions'][0]['note'])->toBe('Launch recipe')
        ->and($history['versions'][0]['changes'][0]['after'])->toBe('180 ml');
});

it('gives prep items the same history: creation, then each recipe or yield change', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->forceFill(['name' => 'Chef Amal'])->save();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $oil = p3Ingredient($ctx['company'], 'Oil', 'ml', '0.004');

    $uuid = $this->postJson('/api/prep-items', [
        'name' => 'Tomato sauce', 'unit' => 'ml', 'prep_yield_quantity' => '2000',
        'lines' => [['ingredient_uuid' => $tomato->uuid, 'quantity' => '1.5', 'unit' => 'kg']],
        'note' => 'House sauce',
    ])->assertCreated()->json('data.uuid');

    $this->patchJson("/api/prep-items/{$uuid}", [
        'prep_yield_quantity' => '1800',
        'lines' => [
            ['ingredient_uuid' => $tomato->uuid, 'quantity' => '1.2', 'unit' => 'kg'],
            ['ingredient_uuid' => $oil->uuid, 'quantity' => '100'],
        ],
        'note' => 'Less tomato, add oil',
    ])->assertOk();

    // A rename is not a recipe change: no new version.
    $this->patchJson("/api/prep-items/{$uuid}", ['name' => 'Pizza sauce'])->assertOk();

    $history = $this->getJson("/api/prep-items/{$uuid}/history")->assertOk()->json('data');
    expect($history['current']['version'])->toBe(2)
        ->and($history['current']['prep_yield_quantity'])->toBe('1800')
        ->and($history['versions'])->toHaveCount(2);

    [$latest, $created] = $history['versions'];
    expect($latest)->toMatchArray([
        'version' => 2, 'event' => 'changed', 'note' => 'Less tomato, add oil', 'yield_before' => '2000', 'yield_after' => '1800',
    ])->and($latest['edited_by']['name'])->toBe('Chef Amal')
        ->and($latest['changes'])->toBe([
            ['ingredient_id' => $tomato->id, 'ingredient' => 'Tomato', 'change' => 'changed', 'before' => '1.5 kg', 'after' => '1.2 kg', 'before_base' => '1500 g', 'after_base' => '1200 g'],
            ['ingredient_id' => $oil->id, 'ingredient' => 'Oil', 'change' => 'added', 'before' => null, 'after' => '100 ml', 'before_base' => null, 'after_base' => '100 ml'],
        ]);
    expect($created)->toMatchArray(['version' => 1, 'event' => 'created', 'note' => 'House sauce', 'yield_after' => '2000']);
});
