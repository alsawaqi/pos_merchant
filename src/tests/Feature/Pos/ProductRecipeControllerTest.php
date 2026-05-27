<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 5b — product recipe replace endpoint.
 *
 * Tests the full slice: PUT /api/products/{uuid}/recipe →
 * UpdateProductRecipeRequest → UpdateProductRecipeAction →
 * pos_product_recipes + pos_product_recipe_versions + audit log
 * + ProductResource (has_recipe, theoretical_cost, recipe_lines).
 *
 * Covers:
 *   - Happy path: PUT replaces the recipe, snapshots the
 *     pre-edit state to a version row, writes one audit row.
 *   - No-op: PUT with the same on-disk shape skips version +
 *     audit (idempotent guard).
 *   - Empty array: clears the recipe, still snapshots the old
 *     state. Phase 8 sale-consumption pipeline relies on this
 *     "no recipe = no deduction" semantic.
 *   - Validation: duplicate ingredient_uuid → 422,
 *     non-positive quantity → 422, > 50 lines → 422.
 *   - Cross-tenant: ingredient from another company → 422,
 *     product from another company → 404.
 *   - Version snapshot: recipe_json contains denormalised
 *     ingredient_name + unit_cost_at_time so the version
 *     survives later ingredient soft-delete.
 *   - ProductResource: has_recipe + theoretical_cost values
 *     match the on-disk lines × ingredient.default_unit_cost.
 *   - Permission gates: CatalogueManage required.
 *
 * Also extends IngredientsController coverage in the sibling
 * test file with the Phase 5b "delete refused when in recipe"
 * guard test.
 */

use App\Enums\IngredientUnit;
use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== HAPPY PATH ===================

it('replaces a product\'s recipe with two ingredient lines + writes a version snapshot + audit row', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create([
        'base_price' => '3.000',
    ]);
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Milk',
        'unit' => IngredientUnit::Litre->value,
        'default_unit_cost' => '1.000',
    ]);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Espresso Beans',
        'unit' => IngredientUnit::Kilogram->value,
        'default_unit_cost' => '15.000',
    ]);

    $response = $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '0.200'],
            ['ingredient_uuid' => $beans->uuid, 'quantity' => '0.018'],
        ],
        'note' => 'initial recipe',
    ])->assertOk();

    // Resource echoes the freshly-saved recipe.
    expect($response->json('data.has_recipe'))->toBeTrue();
    expect($response->json('data.recipe_lines'))->toHaveCount(2);

    // Theoretical cost = 0.200 * 1.000 + 0.018 * 15.000 = 0.470
    expect($response->json('data.theoretical_cost'))->toBe('0.470');

    // DB shape: two recipe lines on the product.
    expect(ProductRecipe::query()->where('product_id', $product->id)->count())->toBe(2);

    // Exactly one version row (the pre-edit snapshot — was empty).
    $versions = ProductRecipeVersion::query()->where('product_id', $product->id)->get();
    expect($versions)->toHaveCount(1);
    expect($versions->first()->recipe_json)->toBe([]); // pre-edit recipe was empty
    expect($versions->first()->note)->toBe('initial recipe');

    // One audit row for the recipe update.
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.product.recipe_updated',
        'auditable_id' => $product->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('preserves ingredient_id keys + line_count in the audit row\'s new_values', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '0.250'],
        ],
    ])->assertOk();

    $audit = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.product.recipe_updated')
        ->where('auditable_id', $product->id)
        ->first();

    expect($audit)->not->toBeNull();
    // new_values is JSON text in the DB; assert key fragments
    // are present rather than parse the encoding.
    expect($audit->new_values)->toContain('"line_count":1');
    expect($audit->new_values)->toContain((string) $milk->id);
});

// =================== NO-OP (IDEMPOTENT) ===================

it('writes ZERO version rows + ZERO audit rows on a no-op PUT (same shape)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Seed the existing recipe directly.
    ProductRecipe::factory()
        ->for($product, 'product')
        ->for($milk, 'ingredient')
        ->create(['quantity' => '0.200', 'unit_at_set' => IngredientUnit::Litre->value]);

    // PUT the SAME shape — same ingredient, same quantity.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '0.200'],
        ],
    ])->assertOk();

    // Zero version snapshots — nothing to snapshot for an
    // unchanged recipe.
    expect(ProductRecipeVersion::query()->where('product_id', $product->id)->count())->toBe(0);

    // Zero audit rows — silent skip.
    $audits = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'catalogue.product.recipe_updated')
        ->where('auditable_id', $product->id)
        ->count();
    expect($audits)->toBe(0);

    // Recipe still intact (we did NOT wipe-and-reinsert).
    expect(ProductRecipe::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('detects a quantity change as a real edit (not a no-op)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    ProductRecipe::factory()
        ->for($product, 'product')
        ->for($milk, 'ingredient')
        ->create(['quantity' => '0.200']);

    // Change the quantity — should NOT be skipped.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '0.300'],
        ],
    ])->assertOk();

    expect(ProductRecipeVersion::query()->where('product_id', $product->id)->count())->toBe(1);
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.product.recipe_updated',
        'auditable_id' => $product->id,
    ]);

    // The single line should now reflect the new quantity.
    $line = ProductRecipe::query()->where('product_id', $product->id)->firstOrFail();
    expect((string) $line->quantity)->toBe('0.300');
});

// =================== EMPTY ARRAY CLEARS ===================

it('clears the entire recipe when PUT sends an empty lines array', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create();

    ProductRecipe::factory()
        ->for($product, 'product')
        ->for($milk, 'ingredient')
        ->create(['quantity' => '0.200']);
    ProductRecipe::factory()
        ->for($product, 'product')
        ->for($sugar, 'ingredient')
        ->create(['quantity' => '0.010', 'sort_order' => 1]);

    $response = $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [],
    ])->assertOk();

    // Recipe wiped.
    expect(ProductRecipe::query()->where('product_id', $product->id)->count())->toBe(0);
    expect($response->json('data.has_recipe'))->toBeFalse();
    expect($response->json('data.theoretical_cost'))->toBe('0.000');

    // Pre-edit snapshot captured both old lines.
    $version = ProductRecipeVersion::query()
        ->where('product_id', $product->id)
        ->firstOrFail();
    expect($version->recipe_json)->toHaveCount(2);
});

// =================== VALIDATION ===================

it('returns 422 when the payload contains a duplicate ingredient_uuid', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $response = $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '0.200'],
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '0.100'],
        ],
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('Duplicate');

    // Nothing persisted on the rollback.
    expect(ProductRecipe::query()->where('product_id', $product->id)->count())->toBe(0);
    expect(ProductRecipeVersion::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('returns 422 when an ingredient_uuid does not exist or belongs to another company', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $mine = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Foreign-tenant ingredient — uuid leaks through the URL
    // but the action's tenant-scoped resolution refuses to
    // match it, breaking the count and aborting.
    $otherCompany = Company::factory()->create();
    $foreign = Ingredient::factory()->for($otherCompany, 'company')->create();

    $response = $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $mine->uuid, 'quantity' => '0.200'],
            ['ingredient_uuid' => $foreign->uuid, 'quantity' => '0.100'],
        ],
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('do not belong');

    // Total rollback — even the legitimate first line is
    // not persisted.
    expect(ProductRecipe::query()->where('product_id', $product->id)->count())->toBe(0);
});

it('returns 422 when quantity is zero or negative', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '0'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity']);

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '-1.000'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity']);
});

it('returns 422 when more than 50 recipe lines are submitted', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    // 51 distinct ingredients — over the cap.
    $lines = [];
    for ($i = 0; $i < 51; $i++) {
        $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
        $lines[] = ['ingredient_uuid' => $ing->uuid, 'quantity' => '0.100'];
    }

    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => $lines])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);
});

it('returns 422 when the lines key is missing entirely', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    // No 'lines' key at all — Request rule is `present`.
    $this->putJson("/api/products/{$product->uuid}/recipe", ['note' => 'oops'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);
});

// =================== CROSS-TENANT ===================

it('returns 404 when targeting a product owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignProduct = Product::factory()->for($otherCompany, 'company')->create();

    $this->putJson("/api/products/{$foreignProduct->uuid}/recipe", [
        'lines' => [],
    ])->assertNotFound();
});

// =================== VERSION SNAPSHOT FIDELITY ===================

it('captures denormalised ingredient name + unit_cost_at_time in the version snapshot', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Whole Milk',
        'unit' => IngredientUnit::Litre->value,
        'default_unit_cost' => '1.200',
    ]);

    // Seed the recipe directly so we can confirm the snapshot
    // captures the EXISTING (pre-edit) state, not the new one.
    ProductRecipe::factory()
        ->for($product, 'product')
        ->for($milk, 'ingredient')
        ->create(['quantity' => '0.250', 'unit_at_set' => IngredientUnit::Litre->value]);

    // Now edit — should snapshot the pre-edit line.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => '0.300'],
        ],
        'note' => 'increased portion',
    ])->assertOk();

    $version = ProductRecipeVersion::query()
        ->where('product_id', $product->id)
        ->firstOrFail();

    expect($version->recipe_json)->toHaveCount(1);
    $snapshot = $version->recipe_json[0];

    // Pre-edit quantity (0.250), NOT the new one (0.300).
    expect($snapshot['quantity'])->toBe('0.250');
    // Denormalised name — survives ingredient soft-delete.
    expect($snapshot['ingredient_name'])->toBe('Whole Milk');
    // Denormalised unit at set time.
    expect($snapshot['unit'])->toBe('l');
    // Denormalised unit cost — historical COGS resilience.
    expect($snapshot['unit_cost_at_time'])->toBe('1.200');
    expect($snapshot['ingredient_id'])->toBe($milk->id);

    // Note stored on the version row, not the snapshot itself.
    expect($version->note)->toBe('increased portion');
    expect($version->edited_by_user_id)->toBe($ctx['user']->id);
});

it('writes a new version row on every edit (append-only ledger)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Edit #1: empty → 1 line.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '0.100']],
    ])->assertOk();

    // Edit #2: change qty.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity' => '0.200']],
    ])->assertOk();

    // Edit #3: clear.
    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [],
    ])->assertOk();

    // Three version rows — one per real edit.
    expect(ProductRecipeVersion::query()->where('product_id', $product->id)->count())->toBe(3);
});

// =================== RESOURCE EXPOSURE ===================

it('exposes has_recipe + theoretical_cost + recipe_lines on the product list endpoint', function (): void {
    $ctx = makeMerchantActor();
    $with = Product::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Latte',
        'base_price' => '2.000',
    ]);
    $without = Product::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Bottled Water',
        'base_price' => '0.300',
    ]);
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'default_unit_cost' => '1.500',
    ]);

    ProductRecipe::factory()
        ->for($with, 'product')
        ->for($milk, 'ingredient')
        ->create(['quantity' => '0.200']); // cost = 0.300

    $rows = $this->getJson('/api/products')->assertOk()->json('data');

    $latte = collect($rows)->firstWhere('uuid', $with->uuid);
    $water = collect($rows)->firstWhere('uuid', $without->uuid);

    expect($latte['has_recipe'])->toBeTrue();
    expect($latte['theoretical_cost'])->toBe('0.300');
    expect($latte['recipe_lines'])->toHaveCount(1);
    expect($latte['recipe_lines'][0]['ingredient']['default_unit_cost'])->toBe('1.500');

    expect($water['has_recipe'])->toBeFalse();
    expect($water['theoretical_cost'])->toBe('0.000');
    expect($water['recipe_lines'])->toBe([]);
});

// =================== PERMISSION GATES ===================

it('forbids a Viewer from updating a product recipe', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => []])
        ->assertForbidden();
});

it('forbids a CashierSupervisor from updating a product recipe', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/recipe", ['lines' => []])
        ->assertForbidden();
});

it('lets an InventoryManager update a product recipe (catalogue.manage in their grant)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity' => '0.100']],
    ])->assertOk();
});
