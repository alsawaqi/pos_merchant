<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part C4 — the add-on groups page's type selector
 * (work order §6.4, owner decision D12, tester call 1, menu audit §7.2):
 * "Extras (with prices)" or "Quick instructions (no price)". An instructions
 * group is several-choice and never required; its options cost nothing, use
 * no stock and sell no linked product. It is bound per product or category
 * with the existing pivots. A product's own group stays Extras; a Remove list
 * is made only by the recipe step.
 * Before: there was no kind; any option could carry a price.
 */

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Ingredient;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('creates a Quick instructions group: several-choice, never required, bound per category and per product', function (): void {
    $ctx = makeMerchantActor();
    $grills = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    $steak = rvmProduct($ctx['company'], 'Steak', '6.000');

    $uuid = $this->postJson('/api/addon-groups', [
        'name' => 'Cooking',
        'name_ar' => 'الطهي',
        'kind' => 'instructions',
        'category_ids' => [$grills->id],
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'instructions')
        ->assertJsonPath('data.selection_mode', 'multi')
        ->assertJsonPath('data.min_selections', null)
        ->json('data.uuid');

    $group = AddOnGroup::query()->where('uuid', $uuid)->sole();
    expect(DB::table('pos_addon_groups')->where('id', $group->id)->value('kind'))->toBe('instructions')
        ->and(DB::table('pos_addon_group_categories')->where('add_on_group_id', $group->id)->value('category_id'))->toBe($grills->id);
    expect(rvmAudit('catalogue.addon_group.created', $group->id)['new']['kind'])->toBe('instructions');

    $this->postJson("/api/addon-groups/{$uuid}/addons", ['name' => 'Well done', 'name_ar' => 'مستوي جيداً'])
        ->assertCreated()->assertJsonPath('data.price_delta', '0.000');
    $this->putJson("/api/products/{$steak->uuid}/addon-groups", ['group_uuids' => [$uuid]])->assertOk();
    expect(DB::table('pos_addon_group_products')->where('add_on_group_id', $group->id)->value('product_id'))->toBe($steak->id);

    // The list shows the kind; an Extras group reads as extras.
    $this->postJson('/api/addon-groups', ['name' => 'Sauces'])->assertCreated()->assertJsonPath('data.kind', 'extras');
    $kinds = collect($this->getJson('/api/addon-groups')->json('data'))->pluck('kind', 'name')->all();
    expect($kinds)->toMatchArray(['Cooking' => 'instructions', 'Sauces' => 'extras']);
});

it('refuses a single-choice or required Quick instructions group', function (): void {
    makeMerchantActor();

    $this->postJson('/api/addon-groups', ['name' => 'Doneness', 'kind' => 'instructions', 'selection_mode' => 'single'])
        ->assertStatus(422)->assertJsonValidationErrors(['selection_mode']);
    $this->postJson('/api/addon-groups', ['name' => 'Doneness', 'kind' => 'instructions', 'min_selections' => 1])
        ->assertStatus(422)->assertJsonValidationErrors(['min_selections']);
    $this->postJson('/api/addon-groups', ['name' => 'Doneness', 'kind' => 'surprise'])
        ->assertStatus(422)->assertJsonValidationErrors(['kind']);
    expect(AddOnGroup::query()->count())->toBe(0);
});

it('keeps every quick instruction free, stockless and unlinked', function (): void {
    $ctx = makeMerchantActor();
    $uuid = $this->postJson('/api/addon-groups', ['name' => 'Spice', 'kind' => 'instructions'])->assertCreated()->json('data.uuid');
    $chili = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $cake = rvmProduct($ctx['company'], 'Cake', '1.000');

    $this->postJson("/api/addon-groups/{$uuid}/addons", ['name' => 'Extra hot', 'price_delta' => '0.100'])
        ->assertStatus(422)->assertJsonValidationErrors(['price_delta']);
    $this->postJson("/api/addon-groups/{$uuid}/addons", ['name' => 'With cake', 'linked_product_uuid' => $cake->uuid])
        ->assertStatus(422)->assertJsonValidationErrors(['linked_product_uuid']);
    $this->postJson("/api/addon-groups/{$uuid}/addons", ['name' => 'Extra chili', 'consumption' => [
        ['type' => 'ingredient', 'ingredient_uuid' => $chili->uuid, 'quantity' => '5'],
    ]])->assertStatus(422)->assertJsonValidationErrors(['consumption']);

    $option = $this->postJson("/api/addon-groups/{$uuid}/addons", ['name' => 'Less spicy', 'price_delta' => '0'])->assertCreated()->json('data.uuid');
    $this->patchJson("/api/addons/{$option}", ['price_delta' => '0.250'])->assertStatus(422)->assertJsonValidationErrors(['price_delta']);
    $this->patchJson("/api/addons/{$option}", ['name' => 'Mild'])->assertOk()->assertJsonPath('data.name', 'Mild');

    expect(AddOn::query()->count())->toBe(1)
        ->and((string) AddOn::query()->sole()->price_delta)->toBe('0.000');
});

it('turns a free Extras group into Quick instructions, but not one with prices, and never a product\'s own group', function (): void {
    $ctx = makeMerchantActor();
    $free = $this->postJson('/api/addon-groups', ['name' => 'Notes', 'selection_mode' => 'single', 'min_selections' => 1])->assertCreated()->json('data.uuid');
    $this->postJson("/api/addon-groups/{$free}/addons", ['name' => 'No ice', 'price_delta' => '0'])->assertCreated();
    $priced = $this->postJson('/api/addon-groups', ['name' => 'Toppings'])->assertCreated()->json('data.uuid');
    $this->postJson("/api/addon-groups/{$priced}/addons", ['name' => 'Cheese', 'price_delta' => '0.200'])->assertCreated();

    $this->patchJson("/api/addon-groups/{$priced}", ['kind' => 'instructions'])->assertStatus(422)->assertJsonValidationErrors(['kind']);
    expect(AddOnGroup::query()->where('uuid', $priced)->value('kind'))->toBe('extras');

    $this->patchJson("/api/addon-groups/{$free}", ['kind' => 'instructions'])->assertOk()
        ->assertJsonPath('data.kind', 'instructions')
        ->assertJsonPath('data.selection_mode', 'multi')
        ->assertJsonPath('data.min_selections', null);
    expect(rvmAudit('catalogue.addon_group.updated', AddOnGroup::query()->where('uuid', $free)->value('id'))['new']['kind'])->toBe('instructions');

    // A product's own group stays Extras.
    $burger = rvmProduct($ctx['company'], 'Burger', '2.000');
    $this->postJson("/api/products/{$burger->uuid}/addon-groups", ['name' => 'Doneness', 'kind' => 'instructions'])
        ->assertStatus(422)->assertJsonValidationErrors(['kind']);
    $own = $this->postJson("/api/products/{$burger->uuid}/addon-groups", ['name' => 'Doneness'])->assertCreated()->json('data.uuid');
    $this->patchJson("/api/addon-groups/{$own}", ['kind' => 'instructions'])->assertStatus(422)->assertJsonValidationErrors(['kind']);
});
