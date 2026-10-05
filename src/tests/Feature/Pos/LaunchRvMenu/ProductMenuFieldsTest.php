<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part C2 — the product wizard's limited-time dates and
 * cooking time (work order §6.2, owner decisions D10 and D11, tester calls 13
 * and 14): saved on create and edit, audited, the product's updated_at moves
 * (devices pick it up by delta); "Until" never before "From"; 0..240 minutes,
 * where 0 (ready at once) is not "not set". The combo editor's item list
 * carries them so it can warn about limited-time items.
 * Before: the fields were dropped (not validated, not saved, not returned).
 */

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function rvmWizardPayload(array $product = [], array $extra = []): array
{
    return array_merge([
        'product' => array_merge(['name' => 'Pumpkin latte', 'base_price' => '1.900', 'stock_mode' => 'untracked'], $product),
        'addon_group_uuids' => [],
        'owned_groups' => [],
        'recipe_lines' => [],
        'component_lines' => [],
        'branches' => null,
        'delivery_prices' => [],
    ], $extra);
}

it('creates a product with its dates and cooking time, audited and returned', function (): void {
    makeMerchantActor();

    $res = $this->postJson('/api/products/wizard', rvmWizardPayload([
        'on_sale_from' => '2026-11-01',
        'on_sale_until' => '2026-11-30',
        'cooking_minutes' => 7,
    ]))->assertCreated()
        ->assertJsonPath('data.on_sale_from', '2026-11-01')
        ->assertJsonPath('data.on_sale_until', '2026-11-30')
        ->assertJsonPath('data.cooking_minutes', 7);

    $product = Product::query()->where('uuid', $res->json('data.uuid'))->sole();
    $row = DB::table('pos_products')->where('id', $product->id)->first();
    expect($row->on_sale_from)->toBe('2026-11-01')
        ->and($row->on_sale_until)->toBe('2026-11-30')
        ->and((int) $row->cooking_minutes)->toBe(7);
    $audit = rvmAudit('catalogue.product.created', $product->id);
    expect($audit['new']['on_sale_from'])->toBe('2026-11-01')
        ->and($audit['new']['cooking_minutes'])->toBe(7);

    $this->getJson("/api/products/{$product->uuid}")->assertOk()->assertJsonPath('data.on_sale_until', '2026-11-30');

    // The plain create endpoint takes them too.
    $this->postJson('/api/products', ['name' => 'Iced tea', 'base_price' => '0.800', 'cooking_minutes' => 0])
        ->assertCreated()->assertJsonPath('data.cooking_minutes', 0)->assertJsonPath('data.on_sale_from', null);
});

it('changes the dates and cooking time on edit, audited, and moves the product updated_at', function (): void {
    $ctx = makeMerchantActor();
    $product = rvmProduct($ctx['company'], 'Mandi', '3.500', ['updated_at' => now()->subDay()]);

    $this->patchJson("/api/products/{$product->uuid}", ['on_sale_from' => '2026-10-20', 'on_sale_until' => '2026-10-31', 'cooking_minutes' => 0])
        ->assertOk()
        ->assertJsonPath('data.on_sale_until', '2026-10-31')
        ->assertJsonPath('data.cooking_minutes', 0);

    $row = DB::table('pos_products')->where('id', $product->id)->first();
    expect((int) $row->cooking_minutes)->toBe(0)
        ->and($row->cooking_minutes)->not->toBeNull()
        ->and((string) $row->updated_at)->toBeGreaterThan(now()->subHour()->toDateTimeString());
    $audit = rvmAudit('catalogue.product.updated', $product->id);
    expect($audit['old'])->toMatchArray(['on_sale_from' => null, 'on_sale_until' => null, 'cooking_minutes' => null])
        ->and($audit['new'])->toMatchArray(['on_sale_from' => '2026-10-20', 'on_sale_until' => '2026-10-31', 'cooking_minutes' => 0]);

    // Clearing them is a change too (0 → not set; dates → always).
    $this->patchJson("/api/products/{$product->uuid}", ['on_sale_from' => null, 'on_sale_until' => null, 'cooking_minutes' => null])->assertOk()
        ->assertJsonPath('data.cooking_minutes', null)
        ->assertJsonPath('data.on_sale_from', null);
    expect(DB::table('pos_products')->where('id', $product->id)->value('cooking_minutes'))->toBeNull();
});

it('refuses an until date before the from date, also against the saved from date, and bad cooking times', function (): void {
    $ctx = makeMerchantActor();
    $product = rvmProduct($ctx['company'], 'Mandi', '3.500', ['on_sale_from' => '2026-11-10']);

    $this->postJson('/api/products/wizard', rvmWizardPayload(['on_sale_from' => '2026-11-10', 'on_sale_until' => '2026-11-01']))
        ->assertStatus(422)->assertJsonValidationErrors(['product.on_sale_until']);
    $this->postJson('/api/products', ['name' => 'X', 'base_price' => '1', 'on_sale_from' => '2026-11-10', 'on_sale_until' => '2026-11-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['on_sale_until']);
    // PATCH sends only the end date: checked against the saved start date.
    $this->patchJson("/api/products/{$product->uuid}", ['on_sale_until' => '2026-11-09'])
        ->assertStatus(422)->assertJsonValidationErrors(['on_sale_until']);
    foreach ([-1, 241, 2.5, 'soon'] as $bad) {
        $this->patchJson("/api/products/{$product->uuid}", ['cooking_minutes' => $bad])
            ->assertStatus(422)->assertJsonValidationErrors(['cooking_minutes']);
    }
    $this->patchJson("/api/products/{$product->uuid}", ['on_sale_from' => '2026-13-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['on_sale_from']);

    expect(DB::table('pos_products')->where('id', $product->id)->value('on_sale_until'))->toBeNull();
});

it('gives the combo editor each item\'s dates and cooking time', function (): void {
    $ctx = makeMerchantActor();
    $item = rvmProduct($ctx['company'], 'Pumpkin pie', '1.200', ['on_sale_until' => '2026-11-30', 'cooking_minutes' => 4]);

    $row = collect($this->getJson('/api/products/addon-link-options')->assertOk()->json('data'))->firstWhere('uuid', $item->uuid);
    expect($row['on_sale_until'])->toBe('2026-11-30')
        ->and($row['on_sale_from'])->toBeNull()
        ->and($row['cooking_minutes'])->toBe(4);
});
