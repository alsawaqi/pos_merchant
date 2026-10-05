<?php

declare(strict_types=1);

/*
 * LAUNCH-P2 P2-1 — "buy big, use small": per-base-unit costs keep 6
 * decimals and ingredient quantities 4, so a gram costs 0.00035 (not 0.000)
 * and an existing kg ingredient can hold 0.3 g (0.0003 kg).
 */

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\StockMovement;
use App\Support\StockDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps a per-gram cost to 6 decimals end to end', function (): void {
    $ctx = makeMerchantActor();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'g', 'default_unit_cost' => '0']);

    // LAUNCH review add-on (A1) — the cost comes from a purchase now (it can
    // no longer be typed): 1 kg for 0.350 OMR is 0.00035 per g.
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'quantity' => '1', 'unit' => 'kg', 'line_cost' => '0.350',
    ]]])->assertCreated();
    $this->getJson('/api/ingredients')->assertOk()->assertJsonPath('data.0.default_unit_cost', '0.00035');
    expect((string) $flour->fresh()->default_unit_cost)->toBe('0.00035');
});

it('lets a kg ingredient hold 0.3 g in recipes and in the ledger', function (): void {
    $ctx = makeMerchantActor();
    $saffron = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'kg', 'default_unit_cost' => '1200.000']);
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'ingredient']);

    $this->putJson("/api/products/{$product->uuid}/recipe", [
        'lines' => [['ingredient_uuid' => $saffron->uuid, 'quantity' => '0.3', 'unit' => 'g']],
    ])->assertOk();
    expect((string) ProductRecipe::query()->where('product_id', $product->id)->sole()->quantity)->toBe('0.0003');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock/adjust", [
        'ingredient_uuid' => $saffron->uuid, 'signed_quantity' => '0.3', 'unit' => 'g', 'note' => 'found a pinch',
    ])->assertCreated()->assertJsonPath('data.quantity', '0.0003');
    expect((string) StockMovement::query()->sole()->quantity)->toBe('0.0003');
});

it('formats ledger numbers with at least 3 and at most the column decimals', function (): void {
    expect(StockDecimal::quantity('6'))->toBe('6.000')
        ->and(StockDecimal::quantity(0.0003))->toBe('0.0003')
        ->and(StockDecimal::quantity('-0.00004'))->toBe('0.000')
        ->and(StockDecimal::quantity(12.34567))->toBe('12.3457')
        ->and(StockDecimal::unitCost('0.000350'))->toBe('0.00035')
        ->and(StockDecimal::unitCost(1.5))->toBe('1.500')
        ->and(StockDecimal::unitCost('0.0000004'))->toBe('0.000')
        ->and(StockDecimal::unitCost('0.0000005'))->toBe('0.000001');
});
