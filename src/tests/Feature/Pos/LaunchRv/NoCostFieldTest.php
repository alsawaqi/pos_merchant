<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 1 — A1 "No cost field" (owner decision D1,
 * tester call 2).
 *
 *   - creating an ingredient takes no cost: a typed cost is refused, the cost
 *     starts at 0 and the item reads "No cost yet" (has_cost false);
 *   - the update refuses a CHANGED cost and accepts the unchanged one an old
 *     portal tab sends back;
 *   - the weighted average: when the old cost is 0 the first priced purchase
 *     sets cost = price paid (it used to blend with 0); a free line (0) still
 *     books no expense and does not move the average;
 *   - has_cost on the resources (ingredient list, recipe lines, prep items,
 *     products' theoretical cost) so the portal can say "No cost yet".
 */

use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('refuses a typed cost when creating an ingredient', function (): void {
    makeMerchantActor();

    $this->postJson('/api/ingredients', ['name' => 'Milk', 'unit' => 'ml', 'default_unit_cost' => '0.0015'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['default_unit_cost']);

    expect(Ingredient::query()->where('name', 'Milk')->exists())->toBeFalse();
});

it('creates an ingredient with no cost yet', function (): void {
    makeMerchantActor();

    $res = $this->postJson('/api/ingredients', ['name' => 'Milk', 'unit' => 'ml'])->assertCreated();

    expect($res->json('data.default_unit_cost'))->toBe('0.000')
        ->and($res->json('data.has_cost'))->toBeFalse();

    // An old portal tab sends "0.000" with the form: still accepted.
    $this->postJson('/api/ingredients', ['name' => 'Oil', 'unit' => 'ml', 'default_unit_cost' => '0.000'])->assertCreated();
});

it('refuses a changed cost on update but accepts the unchanged one', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0.0015');

    $this->patchJson("/api/ingredients/{$milk->uuid}", ['default_unit_cost' => '0.0020'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['default_unit_cost']);
    expect((string) $milk->fresh()->default_unit_cost)->toBe('0.0015');

    $this->patchJson("/api/ingredients/{$milk->uuid}", ['default_unit_cost' => '0.001500', 'name' => 'Fresh milk'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Fresh milk')
        ->assertJsonPath('data.has_cost', true);
});

it('sets the cost to the price paid on the first priced purchase after free stock', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0');
    // 10 l on hand from a free line / a count overage (cost 0 = no cost yet).
    rvStock(null, $milk, '10000');

    $this->postJson('/api/purchase-receipts', [
        'lines' => [[
            'item_type' => 'ingredient',
            'item_uuid' => $milk->uuid,
            'quantity' => '10',
            'unit' => 'l',
            'line_cost' => '10.000',
        ]],
    ])->assertCreated();

    // 10.000 OMR for 10 l = 0.001 per ml — not blended with the free 10 l (0.0005).
    expect((string) $milk->fresh()->default_unit_cost)->toBe('0.001');
});

it('keeps averaging once a cost is known, and a free line moves nothing', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0.001');
    rvStock(null, $milk, '10000');

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'quantity' => '10', 'unit' => 'l', 'line_cost' => '20.000',
    ]]])->assertCreated();
    // (10 l × 0.001 + 10 l × 0.002) / 20 l = 0.0015 per ml.
    expect((string) $milk->fresh()->default_unit_cost)->toBe('0.0015');

    $expenses = DB::table('pos_expenses')->count();
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'quantity' => '5', 'unit' => 'l', 'line_cost' => '0',
    ]]])->assertCreated();
    expect((string) $milk->fresh()->default_unit_cost)->toBe('0.0015')
        ->and(DB::table('pos_expenses')->count())->toBe($expenses);
});

it('says which ingredients have no cost yet in the list and the branch stock', function (): void {
    $ctx = makeMerchantActor();
    rvIngredient($ctx['company'], 'Priced', 'g', '0.002');
    rvIngredient($ctx['company'], 'Unpriced', 'g', '0');

    $rows = collect($this->getJson('/api/ingredients')->assertOk()->json('data'))->keyBy('name');
    expect($rows['Priced']['has_cost'])->toBeTrue()
        ->and($rows['Unpriced']['has_cost'])->toBeFalse();

    $stock = collect($this->getJson("/api/branches/{$ctx['branch']->uuid}/stock")->assertOk()->json('data'))
        ->keyBy(static fn (array $r): string => $r['ingredient']['name']);
    expect($stock['Priced']['ingredient']['has_cost'])->toBeTrue()
        ->and($stock['Unpriced']['ingredient']['has_cost'])->toBeFalse();
});

it('marks recipe lines and the theoretical cost incomplete while an ingredient has no cost', function (): void {
    $ctx = makeMerchantActor();
    $beans = rvIngredient($ctx['company'], 'Beans', 'g', '0.01');
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml', '0');
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'stock_mode' => 'ingredient']);
    foreach ([[$beans, '18'], [$milk, '200']] as $i => [$ingredient, $quantity]) {
        DB::table('pos_product_recipes')->insert([
            'product_id' => $latte->id, 'ingredient_id' => $ingredient->id, 'quantity' => $quantity,
            'unit_at_set' => $ingredient->unit->value, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $product = $this->getJson("/api/products/{$latte->uuid}")->assertOk()->json('data');
    $lines = collect($product['recipe_lines'])->keyBy(static fn (array $l): string => $l['ingredient']['name']);
    expect($lines['Beans']['ingredient']['has_cost'])->toBeTrue()
        ->and($lines['Milk']['ingredient']['has_cost'])->toBeFalse()
        ->and($product['theoretical_cost_complete'])->toBeFalse();
});

it('says a prep item has a cost only when every raw ingredient has one', function (): void {
    $ctx = makeMerchantActor();
    $tomato = rvIngredient($ctx['company'], 'Tomato', 'g', '0.002');
    $basil = rvIngredient($ctx['company'], 'Basil', 'g', '0');
    $sauce = rvIngredient($ctx['company'], 'Sauce', 'g', '0');
    DB::table('pos_ingredients')->where('id', $sauce->id)->update(['is_prep' => true, 'prep_yield_quantity' => '1000']);
    foreach ([[$tomato, '900'], [$basil, '20']] as $i => [$ingredient, $quantity]) {
        DB::table('pos_ingredient_recipes')->insert([
            'prep_ingredient_id' => $sauce->id, 'ingredient_id' => $ingredient->id, 'quantity' => $quantity,
            'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $rows = collect($this->getJson('/api/ingredients?include_prep=1')->assertOk()->json('data'))->keyBy('name');
    expect($rows['Sauce']['has_cost'])->toBeFalse();

    DB::table('pos_ingredients')->where('id', $basil->id)->update(['default_unit_cost' => '0.05']);
    \App\Support\Recipes\PrepGraph::forget((int) $ctx['company']->id);
    $rows = collect($this->getJson('/api/ingredients?include_prep=1')->assertOk()->json('data'))->keyBy('name');
    expect($rows['Sauce']['has_cost'])->toBeTrue();
});
