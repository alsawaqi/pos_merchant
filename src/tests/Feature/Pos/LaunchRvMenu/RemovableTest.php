<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part C3 — "Can be removed" on recipe lines (work
 * order §6.3, owner decision D12, tester calls 1 and 16, menu audit §7.2).
 *
 * The ticked lines live in ONE add-on group the product owns, kind 'remove'
 * ("Remove" / "إزالة", several, never required); each ticked line is a price-0
 * option "NO {label}" / "بدون {label_ar}" with removes_ingredient_id = the
 * line's ingredient, so today's devices show, send and print it. Ticking
 * creates, a new label renames, unticking retires (soft delete); deleting the
 * recipe line retires its option. Saved through its own endpoint gated by
 * catalogue.manage (not "Edit recipes"), tenant-scoped, audited, and the
 * product is touched. The generic add-on endpoints never edit this list.
 * Before: no endpoint, no kind, no removes_ingredient_id.
 */

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function rvmRemoveGroup(Product $product, bool $withTrashed = false): ?object
{
    return DB::table('pos_addon_groups')
        ->where('owner_product_id', $product->id)
        ->where('kind', 'remove')
        ->when(! $withTrashed, fn ($q) => $q->whereNull('deleted_at'))
        ->first();
}

it('ticks recipe lines "Can be removed": one owned Remove group with a free "NO …" option per line', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup, 'onion' => $onion] = rvmBurger($ctx['company']);
    DB::table('pos_products')->where('id', $burger->id)->update(['updated_at' => now()->subDay()]);

    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [
        ['ingredient_uuid' => $ketchup->uuid, 'label' => 'Ketchup', 'label_ar' => null],
        ['ingredient_uuid' => $onion->uuid],
    ]])->assertOk()
        ->assertJsonPath('data.applies_to_stock', true)
        ->assertJsonPath('data.lines.0.ingredient_uuid', $ketchup->uuid)
        ->assertJsonPath('data.lines.0.name', 'NO Ketchup')
        ->assertJsonPath('data.lines.0.name_ar', 'بدون كاتشب')
        ->assertJsonPath('data.lines.1.name', 'NO Onion')
        ->assertJsonPath('data.lines.1.label', 'Onion');

    $group = rvmRemoveGroup($burger);
    expect($group)->not->toBeNull()
        ->and($group->name)->toBe('Remove')
        ->and($group->name_ar)->toBe('إزالة')
        ->and($group->selection_mode)->toBe('multi')
        ->and($group->min_selections)->toBeNull()
        ->and((int) $group->company_id)->toBe($ctx['company']->id)
        ->and((bool) $group->is_global)->toBeFalse()
        ->and(DB::table('pos_addon_group_products')->where('add_on_group_id', $group->id)->where('product_id', $burger->id)->exists())->toBeTrue();
    $options = DB::table('pos_addons')->where('add_on_group_id', $group->id)->whereNull('deleted_at')->orderBy('display_order')->get();
    expect($options->pluck('removes_ingredient_id')->map(fn ($id) => (int) $id)->all())->toBe([$ketchup->id, $onion->id])
        ->and(AddOn::query()->where('add_on_group_id', $group->id)->get()->map(fn (AddOn $a): string => (string) $a->price_delta)->unique()->values()->all())->toBe(['0.000'])
        ->and($options->pluck('linked_product_id')->filter()->all())->toBe([])
        ->and(DB::table('pos_addon_consumptions')->whereIn('add_on_id', $options->pluck('id'))->count())->toBe(0);
    // Devices pick it up by delta; the change is audited.
    expect((string) DB::table('pos_products')->where('id', $burger->id)->value('updated_at'))->toBeGreaterThan(now()->subHour()->toDateTimeString());
    $audit = rvmAudit('catalogue.product.removable_saved', $burger->id);
    expect($audit['old']['removable'])->toBe([])
        ->and(collect($audit['new']['removable'])->pluck('name')->all())->toBe(['NO Ketchup', 'NO Onion']);

    // The wizard's product read shows the group like the product's other groups, and GET reads the ticks.
    $this->getJson("/api/products/{$burger->uuid}/removable")->assertOk()->assertJsonCount(2, 'data.lines');
    $groups = collect($this->getJson("/api/products/{$burger->uuid}")->json('data.addon_groups'));
    expect($groups->firstWhere('kind', 'remove')['addons'][0]['removes_ingredient_id'])->toBe($ketchup->id);
});

it('renames on a new label, retires an unticked line, and brings the same option back when ticked again', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup, 'onion' => $onion] = rvmBurger($ctx['company']);
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $ketchup->uuid], ['ingredient_uuid' => $onion->uuid]]])->assertOk();
    $group = rvmRemoveGroup($burger);
    $ketchupOption = DB::table('pos_addons')->where('removes_ingredient_id', $ketchup->id)->first();
    expect($ketchupOption->name)->toBe('NO Ketchup (Heinz 5 kg)');

    // Rename (label) + untick onion.
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $ketchup->uuid, 'label' => 'Ketchup', 'label_ar' => 'صلصة الطماطم']]])
        ->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.label_ar', 'صلصة الطماطم');
    $renamed = DB::table('pos_addons')->where('id', $ketchupOption->id)->first();
    expect($renamed->name)->toBe('NO Ketchup')
        ->and($renamed->name_ar)->toBe('بدون صلصة الطماطم')
        ->and($renamed->deleted_at)->toBeNull()
        ->and(DB::table('pos_addons')->where('removes_ingredient_id', $onion->id)->value('deleted_at'))->not->toBeNull();

    // Untick all: the group is retired too (an empty list would show on devices).
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => []])->assertOk()->assertJsonCount(0, 'data.lines');
    expect(rvmRemoveGroup($burger))->toBeNull()
        ->and(rvmRemoveGroup($burger, true)->deleted_at)->not->toBeNull();

    // Tick again: the same group and option rows come back (ids never churn).
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $onion->uuid]]])->assertOk();
    expect((int) rvmRemoveGroup($burger)->id)->toBe((int) $group->id)
        ->and(DB::table('pos_addons')->where('removes_ingredient_id', $onion->id)->whereNull('deleted_at')->count())->toBe(1)
        ->and(DB::table('pos_addons')->where('add_on_group_id', $group->id)->count())->toBe(2);
});

it('retires a line\'s option when the recipe line is deleted, and keeps the others', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup, 'onion' => $onion, 'bun' => $bun] = rvmBurger($ctx['company']);
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $ketchup->uuid], ['ingredient_uuid' => $onion->uuid]]])->assertOk();

    $this->putJson("/api/products/{$burger->uuid}/recipe", ['lines' => [
        ['ingredient_uuid' => $onion->uuid, 'quantity' => '15'],
        ['ingredient_uuid' => $bun->uuid, 'quantity' => '1'],
    ]])->assertOk();

    expect(DB::table('pos_addons')->where('removes_ingredient_id', $ketchup->id)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('pos_addons')->where('removes_ingredient_id', $onion->id)->value('deleted_at'))->toBeNull();
    $this->getJson("/api/products/{$burger->uuid}/removable")->assertOk()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.lines.0.ingredient_uuid', $onion->uuid);
    expect(rvmAudit('catalogue.product.removable_saved', $burger->id)['new']['reason'])->toBe('recipe line removed');

    // Clearing the whole recipe retires the list.
    $this->putJson("/api/products/{$burger->uuid}/recipe", ['lines' => []])->assertOk();
    expect(rvmRemoveGroup($burger))->toBeNull();
});

it('only ticks lines of this product\'s recipe, of this company', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup] = rvmBurger($ctx['company']);
    $mayo = rvmIngredient($ctx['company'], 'Mayonnaise');
    $foreign = rvmIngredient(Company::factory()->create(), 'Foreign ketchup');

    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $mayo->uuid]]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.ingredient_uuid']);
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $foreign->uuid]]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.ingredient_uuid']);
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $ketchup->uuid], ['ingredient_uuid' => $ketchup->uuid]]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.1.ingredient_uuid']);
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $ketchup->uuid, 'label' => str_repeat('x', 61)]]])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.label']);
    expect(DB::table('pos_addon_groups')->where('kind', 'remove')->count())->toBe(0);

    // Another company's product is not found.
    $other = rvmBurger(Company::factory()->create());
    $this->getJson("/api/products/{$other['burger']->uuid}/removable")->assertNotFound();
    $this->putJson("/api/products/{$other['burger']->uuid}/removable", ['lines' => []])->assertNotFound();
});

it('needs catalogue.manage to save the ticks (not "Edit recipes"), catalogue.view to read them', function (): void {
    $ctx = rvmActorWith(['catalogue.view', 'catalogue.recipes.manage']);
    ['burger' => $burger, 'ketchup' => $ketchup] = rvmBurger($ctx['company']);

    $this->getJson("/api/products/{$burger->uuid}/removable")->assertOk()->assertJsonPath('data.lines', []);
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $ketchup->uuid]]])->assertForbidden();

    $manager = rvmActorWith(['catalogue.view', 'catalogue.manage']);
    $mine = rvmBurger($manager['company']);
    $this->putJson("/api/products/{$mine['burger']->uuid}/removable", ['lines' => [['ingredient_uuid' => $mine['ketchup']->uuid]]])->assertOk();
});

it('says a removal only prints for a cooked product, and names the group around one the merchant already owns', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $pie, 'onion' => $onion] = rvmBurger($ctx['company'], 'cooked');
    $this->postJson("/api/products/{$pie->uuid}/addon-groups", ['name' => 'Remove'])->assertCreated();

    $this->putJson("/api/products/{$pie->uuid}/removable", ['lines' => [['ingredient_uuid' => $onion->uuid]]])
        ->assertOk()->assertJsonPath('data.applies_to_stock', false);
    expect(rvmRemoveGroup($pie)->name)->toBe('Remove 2');
});

it('creates the ticks with a new product in the wizard', function (): void {
    $ctx = makeMerchantActor();
    $ketchup = rvmIngredient($ctx['company'], 'Ketchup', 'كاتشب');
    $bun = rvmIngredient($ctx['company'], 'Bun', null, 'piece');

    $uuid = $this->postJson('/api/products/wizard', [
        'product' => ['name' => 'Burger', 'base_price' => '2.000', 'stock_mode' => 'ingredient'],
        'addon_group_uuids' => [],
        'owned_groups' => [],
        'recipe_lines' => [['ingredient_uuid' => $ketchup->uuid, 'quantity' => '20'], ['ingredient_uuid' => $bun->uuid, 'quantity' => '1']],
        'removable' => [['ingredient_uuid' => $ketchup->uuid, 'label' => null, 'label_ar' => null]],
        'component_lines' => [],
        'branches' => null,
        'delivery_prices' => [],
    ])->assertCreated()->json('data.uuid');

    $burger = Product::query()->where('uuid', $uuid)->sole();
    expect(DB::table('pos_addons')->where('add_on_group_id', rvmRemoveGroup($burger)->id)->pluck('name')->all())->toBe(['NO Ketchup']);

    // A tick for a line not in the recipe is refused, and nothing is created.
    $this->postJson('/api/products/wizard', [
        'product' => ['name' => 'Wrap', 'base_price' => '2.000', 'stock_mode' => 'ingredient'],
        'addon_group_uuids' => [], 'owned_groups' => [], 'component_lines' => [], 'branches' => null, 'delivery_prices' => [],
        'recipe_lines' => [['ingredient_uuid' => $bun->uuid, 'quantity' => '1']],
        'removable' => [['ingredient_uuid' => $ketchup->uuid]],
    ])->assertStatus(422)->assertJsonValidationErrors(['removable.0.ingredient_uuid']);
    expect(Product::query()->where('name', 'Wrap')->exists())->toBeFalse();
});

it('keeps the Remove list out of every generic add-on edit', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'ketchup' => $ketchup] = rvmBurger($ctx['company']);
    $this->putJson("/api/products/{$burger->uuid}/removable", ['lines' => [['ingredient_uuid' => $ketchup->uuid]]])->assertOk();
    $group = AddOnGroup::query()->where('kind', 'remove')->sole();
    $option = AddOn::query()->where('add_on_group_id', $group->id)->sole();

    $this->patchJson("/api/addon-groups/{$group->uuid}", ['name' => 'Hacked'])->assertStatus(422);
    $this->deleteJson("/api/addon-groups/{$group->uuid}")->assertStatus(422);
    $this->postJson("/api/addon-groups/{$group->uuid}/addons", ['name' => 'NO Bun'])->assertStatus(422);
    $this->patchJson("/api/addons/{$option->uuid}", ['price_delta' => '0.500'])->assertStatus(422);
    $this->deleteJson("/api/addons/{$option->uuid}")->assertStatus(422);
    $this->postJson('/api/addon-groups', ['name' => 'Remove extra', 'kind' => 'remove'])->assertStatus(422)->assertJsonValidationErrors(['kind']);

    expect($group->fresh()->name)->toBe('Remove')
        ->and($option->fresh()->name)->toBe('NO Ketchup (Heinz 5 kg)')
        ->and((string) $option->fresh()->price_delta)->toBe('0.000')
        ->and(AddOn::query()->where('add_on_group_id', $group->id)->count())->toBe(1);
    // Not in the product's editable own groups, nor in the shared list.
    expect(collect($this->getJson("/api/products/{$burger->uuid}/addon-groups")->json('data'))->pluck('uuid'))->not->toContain($group->uuid)
        ->and(collect($this->getJson('/api/addon-groups')->json('data'))->pluck('uuid'))->not->toContain($group->uuid);
    // Editing the product's shared groups keeps it bound.
    $this->putJson("/api/products/{$burger->uuid}/addon-groups", ['group_uuids' => []])->assertOk();
    expect(DB::table('pos_addon_group_products')->where('add_on_group_id', $group->id)->where('product_id', $burger->id)->exists())->toBeTrue();
});
