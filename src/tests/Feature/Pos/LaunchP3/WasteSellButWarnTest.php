<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 fix order 1, K3 — waste follows the selling rule (owner decision
 * 2026-10-02): recording waste — of an ingredient, a prep item or a product —
 * is never refused on the stock numbers. The stock goes below zero, the
 * ledger and the balance stay in step, and the response carries a warning.
 * Before, a short balance refused the waste, and one never-received prep
 * component (water, salt) blocked the whole prep waste.
 */

use App\Models\BranchProduct;
use App\Models\BranchStock;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\StockMovement;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function k3Balance(array $ctx, int $ingredientId): string
{
    return (string) BranchStock::query()->where('branch_id', $ctx['branch']->id)->where('ingredient_id', $ingredientId)->value('quantity');
}

it('records an ingredient waste larger than the branch balance and warns that it is below zero', function (): void {
    $ctx = makeMerchantActor();
    $flour = p3Ingredient($ctx['company'], 'Flour', 'g', '0.0005');
    p3Stock($ctx['branch'], $flour, '2000');

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $flour->uuid, 'quantity' => '3000', 'reason' => 'spoiled',
    ])->assertCreated();

    expect($response->json('warning'))->toBe('Recorded. Flour is now below zero at this branch: it held 2000.000 g and 3000.000 g was wasted, so the balance is -1000.000 g. Count it or receive stock to correct it.')
        ->and(k3Balance($ctx, $flour->id))->toBe('-1000.000')
        ->and((string) WasteRecord::query()->sole()->quantity)->toBe('3000.000');
    // The ledger still sums to the balance.
    expect((float) StockMovement::query()->where('ingredient_id', $flour->id)->sum('quantity'))->toBe(-1000.0);

    // Within the balance: no warning.
    p3Stock($ctx['branch'], $flour, '5000');
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $flour->uuid, 'quantity' => '100', 'reason' => 'spoiled',
    ])->assertCreated()->assertJsonPath('warning', null);
});

it('records a prep waste even when a component was never received, naming every short one', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $oil = p3Ingredient($ctx['company'], 'Oil', 'ml', '0.004');
    $salt = p3Ingredient($ctx['company'], 'Salt', 'g', '0.0003');
    $sauce = p3Prep($ctx['company'], 'Tomato sauce', 'ml', '2000', [[$tomato, '1500'], [$oil, '100'], [$salt, '20']]);
    p3Stock($ctx['branch'], $tomato, '5000');
    p3Stock($ctx['branch'], $oil, '10');
    // Salt: never received at this branch (no balance row at all).

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $sauce->uuid, 'quantity' => '1000', 'reason' => 'spoiled',
    ])->assertCreated();

    expect(WasteRecord::query()->count())->toBe(3)
        ->and(k3Balance($ctx, $tomato->id))->toBe('4250.000')
        ->and(k3Balance($ctx, $oil->id))->toBe('-40.000')
        ->and(k3Balance($ctx, $salt->id))->toBe('-10.000');
    expect($response->json('warning'))
        ->toStartWith('Recorded. Below zero at this branch now: ')
        ->toContain('Oil held 10.000 ml and this waste used 50.000 ml, so it is now -40.000 ml')
        ->toContain('Salt held 0.000 g and this waste used 10.000 g, so it is now -10.000 g')
        ->not->toContain('Tomato');
});

it('records a product waste larger than the shelf and warns that it is below zero', function (string $mode): void {
    $ctx = makeMerchantActor();
    $cola = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cola', 'stock_mode' => $mode, 'cost_price' => '0.200']);
    DB::table('pos_branch_product')->insert([
        'branch_id' => $ctx['branch']->id, 'product_id' => $cola->id, 'is_available' => true, 'stock_qty' => '2.000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = $this->postJson("/api/products/{$cola->uuid}/stock/waste", [
        'branch_uuid' => $ctx['branch']->uuid, 'quantity' => '5', 'reason' => 'dropped',
    ])->assertOk();

    expect($response->json('warning'))->toBe('Recorded. Cola is now below zero on this branch\'s shelf: it showed 2.000 and 5.000 were wasted, so it shows -3.000. Count it to correct it.')
        ->and((string) BranchProduct::query()->where('product_id', $cola->id)->value('stock_qty'))->toBe('-3.000')
        ->and((string) ProductStockMovement::query()->where('product_id', $cola->id)->where('movement_type', 'waste')->sole()->quantity)->toBe('-5.000');
})->with(['unit', 'cooked']);
