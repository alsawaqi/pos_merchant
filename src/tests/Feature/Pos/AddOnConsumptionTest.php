<?php

declare(strict_types=1);

/**
 * PD3b — per-option stock-usage lines (pos_addon_consumptions).
 *
 * An add-on option can carry ingredient lines (converted-at-entry to
 * the ingredient's BASE unit, the recipe convention) and product lines
 * (packaging physical items, prepared cooked products, bought-in unit
 * products), each direction add|remove. Covers: create-with-lines,
 * replace/clear semantics on PATCH, the kind guards, the wizard
 * owned-group path, resource emission, and the group touch that keeps
 * the device config delta honest.
 */

use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\AddOnGroup;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{group: AddOnGroup, beans: Ingredient, cup: Product, patty: Product}
 */
function consumptionFixtures(array $ctx): array
{
    $group = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Size']);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Beans']); // kg
    IngredientAltUnit::query()->create(['company_id' => $ctx['company']->id, 'ingredient_id' => $beans->id, 'name' => 'g', 'factor' => '0.001']);
    $cup = Product::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Cup Large', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'packaging',
    ]);
    $patty = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Patty', 'stock_mode' => 'cooked']);

    return ['group' => $group, 'beans' => $beans, 'cup' => $cup, 'patty' => $patty];
}

it('creates an option with stock-usage lines, converting ingredients to base units', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);

    $res = $this->postJson("/api/addon-groups/{$fx['group']->uuid}/addons", [
        'name' => 'Large',
        'price_delta' => '0.300',
        'consumption' => [
            ['type' => 'ingredient', 'ingredient_uuid' => $fx['beans']->uuid, 'direction' => 'add', 'quantity' => 30, 'unit' => 'g'],
            ['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'direction' => 'add', 'quantity' => 1],
            ['type' => 'product', 'product_uuid' => $fx['patty']->uuid, 'direction' => 'add', 'quantity' => 1],
        ],
    ])->assertCreated();

    // 30 g entered → 0.030 kg stored (convert-at-entry, store-in-base).
    $addon = AddOn::query()->where('name', 'Large')->firstOrFail();
    $lines = $addon->consumptionLines()->get();
    expect($lines)->toHaveCount(3)
        ->and((string) $lines[0]->quantity)->toBe('0.030')
        ->and($lines[0]->unit)->toBe('kg')
        ->and($lines[0]->ingredient_id)->toBe($fx['beans']->id)
        ->and($lines[1]->component_product_id)->toBe($fx['cup']->id)
        ->and($lines[2]->component_product_id)->toBe($fx['patty']->id);

    // The response carries the lines with display names.
    $consumption = $res->json('data.consumption');
    expect($consumption)->toHaveCount(3)
        ->and($consumption[0]['type'])->toBe('ingredient')
        ->and($consumption[0]['quantity'])->toBe('0.030')
        ->and($consumption[0]['ingredient']['name'])->toBe('Beans')
        ->and($consumption[1]['product']['name'])->toBe('Cup Large')
        ->and($consumption[2]['product']['stock_mode'])->toBe('cooked');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.addon.consumption_updated',
        'company_id' => $ctx['company']->id,
    ]);
});

it('replaces lines when the key is present, leaves them when absent, clears on []', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);
    $addon = AddOn::factory()->for($ctx['company'], 'company')->for($fx['group'], 'group')->create(['name' => 'Small']);

    $this->patchJson("/api/addons/{$addon->uuid}", [
        'consumption' => [
            ['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'direction' => 'remove', 'quantity' => 1],
        ],
    ])->assertOk();
    expect($addon->consumptionLines()->count())->toBe(1)
        ->and($addon->consumptionLines()->first()->direction)->toBe('remove');

    // Scalar-only PATCH → lines untouched.
    $this->patchJson("/api/addons/{$addon->uuid}", ['name' => 'Small (renamed)'])->assertOk();
    expect($addon->consumptionLines()->count())->toBe(1);

    // Replace with an ingredient line.
    $this->patchJson("/api/addons/{$addon->uuid}", [
        'consumption' => [
            ['type' => 'ingredient', 'ingredient_uuid' => $fx['beans']->uuid, 'quantity' => '0.010'],
        ],
    ])->assertOk();
    $lines = $addon->consumptionLines()->get();
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->ingredient_id)->toBe($fx['beans']->id)
        // Direction defaults to 'add'; no unit given = already base.
        ->and($lines[0]->direction)->toBe('add')
        ->and((string) $lines[0]->quantity)->toBe('0.010');

    // [] clears.
    $this->patchJson("/api/addons/{$addon->uuid}", ['consumption' => []])->assertOk();
    expect($addon->consumptionLines()->count())->toBe(0);
});

it('touches the group so the device config delta sees the change', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);
    $addon = AddOn::factory()->for($ctx['company'], 'company')->for($fx['group'], 'group')->create();

    $before = $fx['group']->fresh()->updated_at;
    $this->travel(2)->minutes();

    $this->patchJson("/api/addons/{$addon->uuid}", [
        'consumption' => [['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'quantity' => 1]],
    ])->assertOk();

    expect($fx['group']->fresh()->updated_at->gt($before))->toBeTrue();
});

it('guards the line kinds and refs', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);
    $group = $fx['group'];

    // Cross-tenant ingredient → 422 (nothing created).
    $foreignIngredient = Ingredient::factory()->for(Company::factory()->create(), 'company')->create();
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Bad 1',
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $foreignIngredient->uuid, 'quantity' => 1]],
    ])->assertStatus(422);

    // Branch-use physical item → 422.
    $bulb = Product::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Bulb', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'general',
    ]);
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Bad 2',
        'consumption' => [['type' => 'product', 'product_uuid' => $bulb->uuid, 'quantity' => 1]],
    ])->assertStatus(422);

    // Recipe-driven product → 422 (no pieces to consume).
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'stock_mode' => 'ingredient']);
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Bad 3',
        'consumption' => [['type' => 'product', 'product_uuid' => $latte->uuid, 'quantity' => 1]],
    ])->assertStatus(422);

    // Duplicate ref+direction → 422.
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Bad 4',
        'consumption' => [
            ['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'quantity' => 1],
            ['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'quantity' => 2],
        ],
    ])->assertStatus(422);

    // Unknown unit for the ingredient → 422.
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Bad 5',
        'consumption' => [['type' => 'ingredient', 'ingredient_uuid' => $fx['beans']->uuid, 'quantity' => 1, 'unit' => 'barrels']],
    ])->assertStatus(422);

    // A failed create never leaves a half-written option or lines.
    expect(AddOn::query()->where('name', 'like', 'Bad %')->count())->toBe(0)
        ->and(AddOnConsumption::query()->count())->toBe(0);

    // Same direction on add + remove of one ref is legal (not a dupe).
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", [
        'name' => 'Swap cup',
        'consumption' => [
            ['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'direction' => 'add', 'quantity' => 1],
            ['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'direction' => 'remove', 'quantity' => 1],
        ],
    ])->assertCreated();
});

it('rides the wizard owned-group path', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);

    $this->postJson('/api/products/wizard', [
        'product' => ['name' => 'Burger', 'base_price' => '2.000', 'stock_mode' => 'ingredient'],
        'addon_group_uuids' => [],
        'owned_groups' => [[
            'name' => 'Burger extras',
            'selection_mode' => 'multi',
            'options' => [[
                'name' => 'Extra patty',
                'price_delta' => '0.500',
                'consumption' => [
                    ['type' => 'product', 'product_uuid' => $fx['patty']->uuid, 'direction' => 'add', 'quantity' => 1],
                ],
            ]],
        ]],
        'recipe_lines' => [],
        'component_lines' => [],
        'branches' => null,
        'delivery_prices' => [],
    ])->assertCreated();

    $option = AddOn::query()->where('name', 'Extra patty')->firstOrFail();
    $lines = $option->consumptionLines()->get();
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->component_product_id)->toBe($fx['patty']->id)
        ->and($lines[0]->direction)->toBe('add');
});

it('refuses a usage line for the option\'s own linked product (double consumption)', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);

    // Creating with both the link AND a line for the same product → 422.
    $this->postJson("/api/addon-groups/{$fx['group']->uuid}/addons", [
        'name' => 'Extra patty',
        'linked_product_uuid' => $fx['patty']->uuid,
        'consumption' => [['type' => 'product', 'product_uuid' => $fx['patty']->uuid, 'quantity' => 1]],
    ])->assertStatus(422);
    expect(AddOn::query()->where('name', 'Extra patty')->exists())->toBeFalse();

    // Re-linking onto a product an existing line consumes → 422 too.
    $created = $this->postJson("/api/addon-groups/{$fx['group']->uuid}/addons", [
        'name' => 'Patty side',
        'consumption' => [['type' => 'product', 'product_uuid' => $fx['patty']->uuid, 'quantity' => 1]],
    ])->assertCreated()->json('data');
    $this->patchJson("/api/addons/{$created['uuid']}", ['linked_product_uuid' => $fx['patty']->uuid])
        ->assertStatus(422);
});

it('rolls back scalar changes when the lines are rejected (atomic PATCH)', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);
    $addon = AddOn::factory()->for($ctx['company'], 'company')->for($fx['group'], 'group')
        ->create(['name' => 'Small', 'price_delta' => '0.300']);

    // Rename + price change + a bad line (duplicate) in ONE payload.
    $this->patchJson("/api/addons/{$addon->uuid}", [
        'name' => 'Small (renamed)',
        'price_delta' => '0.500',
        'consumption' => [
            ['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'quantity' => 1],
            ['type' => 'product', 'product_uuid' => $fx['cup']->uuid, 'quantity' => 2],
        ],
    ])->assertStatus(422);

    // Nothing committed: the 422 means NOTHING saved.
    $fresh = $addon->fresh();
    expect($fresh->name)->toBe('Small')
        ->and((string) $fresh->price_delta)->toBe('0.300')
        ->and($addon->consumptionLines()->count())->toBe(0);
});

it('lets an unrelated edit through when an untouched line\'s product changed kind', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);
    $created = $this->postJson("/api/addon-groups/{$fx['group']->uuid}/addons", [
        'name' => 'Extra patty',
        'consumption' => [['type' => 'product', 'product_uuid' => $fx['patty']->uuid, 'quantity' => 1]],
    ])->assertCreated()->json('data');

    // The patty later stops being piece-counted (made-to-order now).
    $fx['patty']->update(['stock_mode' => 'ingredient']);

    // A pure rename re-sends the identical line set (the modal owns the
    // full set) — the unchanged lines must NOT block the rename.
    $this->patchJson("/api/addons/{$created['uuid']}", [
        'name' => 'Extra patty (renamed)',
        'consumption' => [['type' => 'product', 'product_uuid' => $fx['patty']->uuid, 'quantity' => '1.000']],
    ])->assertOk();
    expect(AddOn::query()->where('uuid', $created['uuid'])->value('name'))->toBe('Extra patty (renamed)');

    // CHANGING the set still re-validates kinds → 422.
    $this->patchJson("/api/addons/{$created['uuid']}", [
        'consumption' => [['type' => 'product', 'product_uuid' => $fx['patty']->uuid, 'quantity' => 2]],
    ])->assertStatus(422);
});

it('emits consumption on the shared add-on group index', function (): void {
    $ctx = makeMerchantActor();
    $fx = consumptionFixtures($ctx);
    $this->postJson("/api/addon-groups/{$fx['group']->uuid}/addons", [
        'name' => 'Large',
        'consumption' => [
            ['type' => 'ingredient', 'ingredient_uuid' => $fx['beans']->uuid, 'quantity' => '0.030'],
        ],
    ])->assertCreated();

    $groups = $this->getJson('/api/addon-groups')->assertOk()->json('data');
    $size = collect($groups)->firstWhere('name', 'Size');
    $large = collect($size['addons'])->firstWhere('name', 'Large');

    expect($large['consumption'])->toHaveCount(1)
        ->and($large['consumption'][0]['ingredient']['name'])->toBe('Beans');
});
