<?php

declare(strict_types=1);

/**
 * LAUNCH combo add-on, Part A item 4 (owner decision 7): a Remove option may
 * have a minus price ("No cheese −0.100"); the default is no change. Only a
 * Remove option: an Extras option stays at 0 or more and a quick
 * instruction at 0. Before: every option was 0 or more and a Remove option
 * was always 0.
 */

use App\Models\AddOn;
use App\Models\AddOnGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../LaunchRvMenu/helpers.php';

it('saves a minus price on a "Can be removed" tick, shows it back and keeps 0 as the default', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'onion' => $cheese, 'ketchup' => $ketchup] = rvmBurger($ctx['company']);

    $state = rvmTick($burger, [
        ['ingredient_uuid' => $cheese->uuid, 'label' => 'Cheese', 'label_ar' => 'جبن', 'price' => '-0.100'],
        ['ingredient_uuid' => $ketchup->uuid, 'label' => null, 'label_ar' => null],
    ])->assertOk()->json('data');

    expect(collect($state['lines'])->pluck('price', 'label')->all())->toBe(['Cheese' => '-0.100', 'Ketchup (Heinz 5 kg)' => '0.000']);
    $option = AddOn::query()->where('removes_ingredient_id', $cheese->id)->sole();
    expect((string) $option->price_delta)->toBe('-0.100');

    // A save that sends no price (an older open page) keeps the saved one.
    rvmTick($burger, [
        ['ingredient_uuid' => $cheese->uuid, 'label' => 'Cheese', 'label_ar' => 'جبن'],
        ['ingredient_uuid' => $ketchup->uuid, 'label' => 'Tomato sauce', 'label_ar' => null],
    ])->assertOk();
    expect((string) $option->fresh()->price_delta)->toBe('-0.100');
});

it('refuses a Remove option priced above 0', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'onion' => $cheese] = rvmBurger($ctx['company']);

    rvmTick($burger, [['ingredient_uuid' => $cheese->uuid, 'label' => 'Cheese', 'label_ar' => null, 'price' => '0.100']])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.price']);
    expect(AddOn::query()->where('removes_ingredient_id', $cheese->id)->exists())->toBeFalse();
});

it('refuses a minus price on an Extras option or a quick instruction', function (): void {
    $ctx = makeMerchantActor();
    ['burger' => $burger, 'onion' => $cheese] = rvmBurger($ctx['company']);
    rvmTick($burger, [['ingredient_uuid' => $cheese->uuid, 'label' => 'Cheese', 'label_ar' => null]])->assertOk();
    $remove = AddOn::query()->where('removes_ingredient_id', $cheese->id)->sole();
    $extras = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Extras']);
    $instructions = AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Instructions', 'kind' => 'instructions', 'selection_mode' => 'multi']);

    // A Remove option's price is set from the recipe ticks (the option endpoints keep refusing to edit it).
    $this->patchJson("/api/addons/{$remove->uuid}", ['price_delta' => '0.150'])->assertStatus(422);
    expect((string) $remove->fresh()->price_delta)->toBe('0.000');

    $this->postJson("/api/addon-groups/{$extras->uuid}/addons", ['name' => 'Discount sauce', 'price_delta' => '-0.100'])
        ->assertStatus(422)->assertJsonValidationErrors(['price_delta']);
    $this->postJson("/api/addon-groups/{$extras->uuid}/addons", ['name' => 'Extra cheese', 'price_delta' => '0.200'])->assertCreated();
    $this->postJson("/api/addon-groups/{$instructions->uuid}/addons", ['name' => 'Well done', 'price_delta' => '-0.100'])
        ->assertStatus(422)->assertJsonValidationErrors(['price_delta']);
    expect(AddOn::query()->where('price_delta', '<', 0)->count())->toBe(0);
});
