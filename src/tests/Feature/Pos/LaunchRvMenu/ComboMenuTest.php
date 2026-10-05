<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part C1 — the combo editor (work order §6.1, owner
 * decisions D9–D11, tester calls 13–15, 18):
 *   - the main slot ("Make it a meal?"): at most one per combo, only on a
 *     slot where exactly one item is picked; moving it audits and moves the
 *     combo's updated_at (devices re-read it by delta);
 *   - the daily hours, the limited-time dates and the cooking time are saved
 *     (the editor used to wipe the hours) and audited.
 * Before: no is_main, no dates and no cooking time were saved or checked.
 */

use App\Models\ComboSlot;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('saves the main slot of a combo and returns it', function (): void {
    $ctx = makeMerchantActor();
    $items = rvmComboItems($ctx['company']);

    $res = $this->postJson('/api/combos', rvmComboPayload($items))
        ->assertCreated()
        ->assertJsonPath('data.combo.slots.0.is_main', true)
        ->assertJsonPath('data.combo.slots.1.is_main', false);

    $combo = Product::query()->where('uuid', $res->json('data.uuid'))->sole();
    expect(ComboSlot::query()->where('combo_product_id', $combo->id)->where('is_main', true)->pluck('name')->all())->toBe(['Burger']);
    $this->getJson("/api/combos/{$combo->uuid}")->assertOk()->assertJsonPath('data.combo.slots.0.is_main', true);
    expect(rvmAudit('catalogue.combo.slots_saved', $combo->id)['new']['slots'][0]['is_main'])->toBeTrue();
});

it('refuses two mains, and a main on a slot where not exactly one item is picked', function (): void {
    $ctx = makeMerchantActor();
    $items = rvmComboItems($ctx['company']);

    $payload = rvmComboPayload($items);
    $payload['slots'][1]['is_main'] = true;
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['slots.1.is_main']);

    // "Burgers, pick 4" is never a main (tester call 15).
    $payload = rvmComboPayload($items);
    $payload['slots'][0]['min_choices'] = 4;
    $payload['slots'][0]['max_choices'] = 4;
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['slots.0.is_main']);

    // An optional single slot (least 0) cannot pre-pick either.
    $payload = rvmComboPayload($items);
    $payload['slots'][0]['min_choices'] = 0;
    $this->postJson('/api/combos', $payload)->assertStatus(422)->assertJsonValidationErrors(['slots.0.is_main']);

    expect(Product::query()->where('product_type', 'combo')->count())->toBe(0);
});

it('moves the main to another slot in one save, audited, and moves the combo updated_at', function (): void {
    $ctx = makeMerchantActor();
    $items = rvmComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', rvmComboPayload($items))->assertCreated()->json('data.uuid');
    $combo = Product::query()->where('uuid', $uuid)->sole();
    $slots = ComboSlot::query()->where('combo_product_id', $combo->id)->orderBy('sort_order')->get();
    DB::table('pos_products')->where('id', $combo->id)->update(['updated_at' => now()->subDay()]);

    $payload = rvmComboPayload($items);
    $payload['slots'][0]['id'] = $slots[0]->id;
    $payload['slots'][1]['id'] = $slots[1]->id;
    $payload['slots'][0]['is_main'] = false;
    $payload['slots'][1]['is_main'] = true;
    $this->putJson("/api/combos/{$uuid}", $payload)->assertOk()
        ->assertJsonPath('data.combo.slots.0.is_main', false)
        ->assertJsonPath('data.combo.slots.1.is_main', true);

    expect(ComboSlot::query()->find($slots[1]->id)->is_main)->toBeTrue()
        ->and(ComboSlot::query()->find($slots[0]->id)->is_main)->toBeFalse()
        ->and((string) DB::table('pos_products')->where('id', $combo->id)->value('updated_at'))->toBeGreaterThan(now()->subHour()->toDateTimeString());
    $audit = rvmAudit('catalogue.combo.slots_saved', $combo->id);
    expect($audit['old']['slots'][0]['is_main'])->toBeTrue()
        ->and($audit['new']['slots'][1]['is_main'])->toBeTrue();
});

it('keeps the saved main when an older open page sends no main flag', function (): void {
    $ctx = makeMerchantActor();
    $items = rvmComboItems($ctx['company']);
    $uuid = $this->postJson('/api/combos', rvmComboPayload($items))->assertCreated()->json('data.uuid');
    $combo = Product::query()->where('uuid', $uuid)->sole();
    $slots = ComboSlot::query()->where('combo_product_id', $combo->id)->orderBy('sort_order')->get();

    $payload = rvmComboPayload($items, ['base_price' => '3.750']);
    foreach ($payload['slots'] as $i => $slot) {
        unset($payload['slots'][$i]['is_main']);
        $payload['slots'][$i]['id'] = $slots[$i]->id;
    }
    $this->putJson("/api/combos/{$uuid}", $payload)->assertOk()->assertJsonPath('data.combo.slots.0.is_main', true);
});

it('saves a combo\'s daily hours, dates and cooking time, audited, and keeps the dates when a page leaves them out', function (): void {
    $ctx = makeMerchantActor();
    $items = rvmComboItems($ctx['company']);

    $uuid = $this->postJson('/api/combos', rvmComboPayload($items, [
        'available_from' => '11:00:00',
        'available_until' => '15:00:00',
        'on_sale_from' => '2026-11-01',
        'on_sale_until' => '2026-11-30',
        'cooking_minutes' => 12,
    ]))->assertCreated()
        ->assertJsonPath('data.available_from', '11:00:00')
        ->assertJsonPath('data.on_sale_from', '2026-11-01')
        ->assertJsonPath('data.on_sale_until', '2026-11-30')
        ->assertJsonPath('data.cooking_minutes', 12)
        ->json('data.uuid');
    $combo = Product::query()->where('uuid', $uuid)->sole();
    expect(DB::table('pos_products')->where('id', $combo->id)->value('on_sale_until'))->toBe('2026-11-30');

    // A changed end date is audited on the product.
    $payload = rvmComboPayload($items, ['on_sale_from' => '2026-11-01', 'on_sale_until' => '2026-12-15', 'cooking_minutes' => 0, 'available_from' => '11:00:00', 'available_until' => '15:00:00']);
    $this->putJson("/api/combos/{$uuid}", $payload)->assertOk()
        ->assertJsonPath('data.on_sale_until', '2026-12-15')
        ->assertJsonPath('data.cooking_minutes', 0)
        ->assertJsonPath('data.available_until', '15:00:00');
    $audit = rvmAudit('catalogue.product.updated', $combo->id);
    expect($audit['old']['on_sale_until'])->toBe('2026-11-30')
        ->and($audit['new']['on_sale_until'])->toBe('2026-12-15')
        ->and($audit['old']['cooking_minutes'])->toBe(12)
        ->and($audit['new']['cooking_minutes'])->toBe(0);

    // A page that does not know the new fields keeps them.
    $this->putJson("/api/combos/{$uuid}", rvmComboPayload($items))->assertOk()
        ->assertJsonPath('data.on_sale_until', '2026-12-15')
        ->assertJsonPath('data.cooking_minutes', 0);
});

it('refuses an until date before the from date and a cooking time outside 0 to 240 on a combo', function (): void {
    $ctx = makeMerchantActor();
    $items = rvmComboItems($ctx['company']);

    $this->postJson('/api/combos', rvmComboPayload($items, ['on_sale_from' => '2026-11-10', 'on_sale_until' => '2026-11-09']))
        ->assertStatus(422)->assertJsonValidationErrors(['on_sale_until']);
    $this->postJson('/api/combos', rvmComboPayload($items, ['on_sale_from' => '10/11/2026']))
        ->assertStatus(422)->assertJsonValidationErrors(['on_sale_from']);
    foreach ([-1, 241, 'soon'] as $bad) {
        $this->postJson('/api/combos', rvmComboPayload($items, ['cooking_minutes' => $bad]))
            ->assertStatus(422)->assertJsonValidationErrors(['cooking_minutes']);
    }
    // The same day is a one-day item.
    $this->postJson('/api/combos', rvmComboPayload($items, ['on_sale_from' => '2026-11-10', 'on_sale_until' => '2026-11-10', 'cooking_minutes' => 240]))
        ->assertCreated();
});
