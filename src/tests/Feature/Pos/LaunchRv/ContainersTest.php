<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 2 — A2 containers (owner decision D2,
 * tester call 5).
 *
 *   - several containers per item, the same word with different sizes
 *     ("bottle 1.5 l" and "bottle 500 ml"); the same name AND size twice is
 *     refused;
 *   - nested: "crate holds 12 × bottle 1 l" (factor = 12 × the bottle's);
 *     only a container of the same item, a whole number ≥ 2, at most 3 deep;
 *   - server-made display_name / display_name_ar and a token per container;
 *   - the token works wherever a unit comes in (the converter, recipe lines,
 *     waste / transfer / restock requests); a NAME still resolves while one
 *     live container has it, and an ambiguous name is refused clearly;
 *   - a container's size is locked once used (the name stays editable);
 *   - no restore-by-name: re-adding a deleted name creates a new row.
 */

use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Models\IngredientAltUnit;
use App\Models\Product;
use App\Models\ProductRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('keeps several containers with the same word in different sizes', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');

    $big = $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'bottle', 'amount' => '1.5', 'unit' => 'l'])->assertCreated();
    $small = $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'bottle', 'amount' => '500', 'unit' => 'ml', 'name_ar' => 'زجاجة'])->assertCreated();

    expect($big->json('data.display_name'))->toBe('bottle 1.5 l')
        ->and($small->json('data.display_name'))->toBe('bottle 500 ml')
        ->and($small->json('data.display_name_ar'))->toBe('زجاجة 500 ml')
        ->and($big->json('data.token'))->toStartWith('#')
        ->and(strlen((string) $big->json('data.token')))->toBe(23);

    // The same name AND size again is refused.
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'Bottle', 'amount' => '1500', 'unit' => 'ml'])->assertStatus(422);

    $listed = collect($this->getJson('/api/ingredients')->assertOk()->json('data'))->firstWhere('uuid', $milk->uuid);
    expect(collect($listed['alt_units'])->pluck('display_name')->all())->toBe(['bottle 1.5 l', 'bottle 500 ml']);
});

it('nests a container in another of the same item', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'bottle', 'amount' => '1', 'unit' => 'l'])->assertCreated()->json('data');

    $crate = $this->postJson("/api/ingredients/{$milk->uuid}/units", [
        'name' => 'crate', 'contains_unit_uuid' => $bottle['uuid'], 'contains_quantity' => 12,
    ])->assertCreated()->json('data');

    expect($crate['factor'])->toBe('12000.0000')
        ->and($crate['display_name'])->toBe('crate (12 × bottle 1 l)')
        ->and($crate['contains_unit_uuid'])->toBe($bottle['uuid'])
        ->and($crate['contains_quantity'])->toBe('12')
        ->and($crate['leaf_uuid'])->toBe($bottle['uuid'])
        ->and($crate['leaf_pieces'])->toBe('12');

    // 3 crates = 36 l, through the converter.
    expect(app(IngredientUnitConverter::class)->toBase($milk->fresh(), '3', $crate['token']))->toBe(36000.0);

    // A pallet of crates is 3 deep — fine; a fourth level is refused.
    $pallet = $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'pallet', 'contains_unit_uuid' => $crate['uuid'], 'contains_quantity' => 4])->assertCreated()->json('data');
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'truck', 'contains_unit_uuid' => $pallet['uuid'], 'contains_quantity' => 2])->assertStatus(422);

    // Not 1 of something, and never another item's container.
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'single', 'contains_unit_uuid' => $bottle['uuid'], 'contains_quantity' => 1])->assertStatus(422);
    $oil = rvIngredient($ctx['company'], 'Oil', 'ml');
    $this->postJson("/api/ingredients/{$oil->uuid}/units", ['name' => 'crate', 'contains_unit_uuid' => $bottle['uuid'], 'contains_quantity' => 12])->assertStatus(422);

    // The bottle cannot go while the crate holds it.
    $this->deleteJson("/api/ingredients/{$milk->uuid}/units/{$bottle['uuid']}")->assertStatus(422);
});

it('saves nested containers with the ingredient', function (): void {
    makeMerchantActor();

    $res = $this->postJson('/api/ingredients', [
        'name' => 'Milk',
        'unit' => 'ml',
        'pack_sizes' => [
            ['name' => 'bottle', 'amount' => '1', 'unit' => 'l'],
            ['name' => 'bottle', 'amount' => '500', 'unit' => 'ml'],
            ['name' => 'crate', 'contains_index' => 0, 'contains_quantity' => 12],
        ],
    ])->assertCreated();

    expect(collect($res->json('data.alt_units'))->pluck('display_name')->all())
        ->toBe(['bottle 1 l', 'bottle 500 ml', 'crate (12 × bottle 1 l)']);

    // A nested row may only hold a row above it.
    $this->postJson('/api/ingredients', [
        'name' => 'Oil', 'unit' => 'ml',
        'pack_sizes' => [['name' => 'crate', 'contains_index' => 1, 'contains_quantity' => 12], ['name' => 'bottle', 'amount' => '1', 'unit' => 'l']],
    ])->assertStatus(422);
});

it('resolves a name while it is unique and refuses it once it is ambiguous', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $big = rvContainer($milk, 'bottle', '1500');
    $converter = app(IngredientUnitConverter::class);

    expect($converter->toBase($milk->fresh(), '2', 'bottle'))->toBe(3000.0);

    $small = rvContainer($milk, 'bottle', '500');
    expect(fn () => $converter->toBase($milk->fresh(), '2', 'bottle'))
        ->toThrow(RuntimeException::class, "several containers called 'bottle'");
    expect($converter->toBase($milk->fresh(), '2', rvToken($small)))->toBe(1000.0)
        ->and($converter->toBase($milk->fresh(), '2', '#'.rvContainerUuid($big)))->toBe(3000.0);

    // A request with the ambiguous name is a clean 422; the token works.
    $branch = $ctx['branch'];
    rvStock($branch, $milk, '10000');
    $this->postJson("/api/branches/{$branch->uuid}/waste", ['ingredient_uuid' => $milk->uuid, 'quantity' => '1', 'unit' => 'bottle', 'reason' => 'spoiled'])
        ->assertStatus(422);
    $this->postJson("/api/branches/{$branch->uuid}/waste", ['ingredient_uuid' => $milk->uuid, 'quantity' => '1', 'unit' => rvToken($small), 'reason' => 'spoiled'])
        ->assertCreated()
        ->assertJsonPath('data.quantity', '500.000');
});

it('never applies another item\'s container through a token', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $oil = rvIngredient($ctx['company'], 'Oil', 'ml');
    $drum = rvContainer($oil, 'drum', '20000');

    expect(fn () => app(IngredientUnitConverter::class)->toBase($milk->fresh(), '1', rvToken($drum)))
        ->toThrow(RuntimeException::class);
});

it('stores recipe lines by token and reopens an old name line as the container', function (): void {
    $ctx = makeMerchantActor();
    $cups = rvIngredient($ctx['company'], 'Cups', 'piece', '0.01');
    $box = rvContainer($cups, 'box', '12');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    // An older line typed by name …
    DB::table('pos_product_recipes')->insert([
        'product_id' => $product->id, 'ingredient_id' => $cups->id, 'quantity' => '24', 'unit_at_set' => 'piece',
        'entered_unit' => 'box', 'entered_quantity' => '2', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // … reopens as the box, by its token, while "box" is unique.
    $line = $this->getJson("/api/products/{$product->uuid}")->assertOk()->json('data.recipe_lines.0');
    expect($line['entered_unit'])->toBe(rvToken($box))->and($line['entered_quantity'])->toBe('2');

    // Re-saving it untouched writes no new recipe version.
    $versions = DB::table('pos_product_recipe_versions')->count();
    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '2', 'unit' => rvToken($box)]]])->assertOk();
    expect(DB::table('pos_product_recipe_versions')->count())->toBe($versions);

    // A second box size: lines name the container by token, never by the shared word.
    $bigBox = rvContainer($cups, 'box', '50');
    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '1', 'unit' => 'box']]])->assertStatus(422);
    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => [['ingredient_uuid' => $cups->uuid, 'quantity' => '1', 'unit' => rvToken($bigBox)]]])->assertOk();

    $stored = ProductRecipe::query()->where('product_id', $product->id)->firstOrFail();
    expect((string) $stored->quantity)->toBe('50.000')
        ->and($stored->entered_unit)->toBe(rvToken($bigBox))
        ->and(strlen((string) $stored->entered_unit))->toBeLessThanOrEqual(32);
});

it('locks a container size once it is used but keeps its name editable', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $bottle = rvContainer($milk, 'bottle', '1000');
    $uuid = rvContainerUuid($bottle);

    // Unused: the size may still change.
    $this->patchJson("/api/ingredients/{$milk->uuid}/units/{$uuid}", ['amount' => '1.5', 'unit' => 'l'])->assertOk();

    // Used in the breakdown: the size is locked, the name is not.
    rvBreakdown($ctx['company'], $ctx['branch'], $milk, $bottle, '3');
    $this->patchJson("/api/ingredients/{$milk->uuid}/units/{$uuid}", ['amount' => '2', 'unit' => 'l'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'size cannot change'));
    $this->patchJson("/api/ingredients/{$milk->uuid}/units/{$uuid}", ['name' => 'big bottle', 'name_ar' => 'زجاجة كبيرة'])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'big bottle 1.5 l');

    $units = collect($this->getJson("/api/ingredients/{$milk->uuid}/units")->assertOk()->json('data'));
    expect($units->first()['size_locked'])->toBeTrue();

    // A recipe token locks too.
    $box = rvContainer($milk, 'jug', '2000');
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);
    DB::table('pos_product_recipes')->insert([
        'product_id' => $product->id, 'ingredient_id' => $milk->id, 'quantity' => '2000', 'unit_at_set' => 'ml',
        'entered_unit' => rvToken($box), 'entered_quantity' => '1', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->patchJson("/api/ingredients/{$milk->uuid}/units/".rvContainerUuid($box), ['amount' => '3', 'unit' => 'l'])->assertStatus(422);
});

it('creates a new row when a removed container name is added again', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');
    $old = $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'crate', 'amount' => '12', 'unit' => 'l'])->assertCreated()->json('data');
    $this->deleteJson("/api/ingredients/{$milk->uuid}/units/{$old['uuid']}")->assertNoContent();

    $new = $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => 'crate', 'amount' => '6', 'unit' => 'l'])->assertCreated()->json('data');

    expect($new['uuid'])->not->toBe($old['uuid'])
        ->and(IngredientAltUnit::withTrashed()->where('uuid', $old['uuid'])->first()?->trashed())->toBeTrue()
        ->and((string) IngredientAltUnit::withTrashed()->where('uuid', $old['uuid'])->value('factor'))->toBe('12000.0000');
});

it('refuses container names that look like tokens', function (): void {
    $ctx = makeMerchantActor();
    $milk = rvIngredient($ctx['company'], 'Milk', 'ml');

    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => '#crate', 'amount' => '12', 'unit' => 'l'])->assertStatus(422);
    $this->postJson("/api/ingredients/{$milk->uuid}/units", ['name' => '@piece', 'amount' => '12', 'unit' => 'l'])->assertStatus(422);
});
