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
 * Before: no dates and no cooking time were saved or checked.
 * LAUNCH combo add-on: the "main slot" tests retired with the slots; meals
 * are their own setups (tests/Feature/Pos/LaunchCombo).
 */

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

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
