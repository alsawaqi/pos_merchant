<?php

declare(strict_types=1);

/*
 * LAUNCH-P2 P2-7 — sell, but warn (portal side).
 *  - the branch stock page lists EVERY ingredient of the branch (a missing
 *    stock row reads 0), with its stock value (quantity × average cost);
 *    negative stock is flagged, below-minimum is flagged, and a "Low stock"
 *    filter keeps just those;
 *  - the dashboard carries a Low stock card: negative and below-minimum
 *    counts per branch.
 */

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function p2StockFixture(array $ctx): array
{
    $make = fn (string $name, array $attrs): Ingredient => Ingredient::factory()->for($ctx['company'], 'company')
        ->create(array_merge(['name' => $name, 'unit' => 'g', 'default_unit_cost' => '0.0005', 'min_stock_threshold' => null], $attrs));

    $ok = $make('Ok Rice', ['min_stock_threshold' => '100']);
    $never = $make('Never Stocked Salt', ['min_stock_threshold' => '50']);
    $oversold = $make('Oversold Cheese', ['default_unit_cost' => '0.004']);
    $make('Retired Herb', ['status' => 'inactive']);
    $retiredHeld = $make('Retired But Held', ['status' => 'inactive']);

    BranchStock::factory()->for($ctx['branch'], 'branch')->for($ok, 'ingredient')->create(['quantity' => '500']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($oversold, 'ingredient')->create(['quantity' => '-20']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($retiredHeld, 'ingredient')->create(['quantity' => '10']);

    return compact('ok', 'never', 'oversold', 'retiredHeld');
}

it('lists every ingredient of the branch with its value and a negative / below-minimum flag', function (): void {
    $ctx = makeMerchantActor();
    $f = p2StockFixture($ctx);

    $response = $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock")->assertOk();
    $rows = collect($response->json('data'))->keyBy('ingredient.name');

    expect($rows->keys()->sort()->values()->all())
        ->toBe(['Never Stocked Salt', 'Ok Rice', 'Oversold Cheese', 'Retired But Held']);

    expect($rows['Never Stocked Salt'])->toMatchArray([
        'id' => null, 'quantity' => '0.000', 'stock_status' => 'below_minimum', 'stock_value' => '0.000', 'has_stock_row' => false,
    ]);
    expect($rows['Oversold Cheese'])->toMatchArray([
        'quantity' => '-20.000', 'stock_status' => 'negative', 'stock_value' => '-0.080', 'health_level' => 'critical',
    ]);
    expect($rows['Ok Rice'])->toMatchArray(['quantity' => '500.000', 'stock_status' => 'ok', 'stock_value' => '0.250']);

    // Lowest quantity first.
    expect($response->json('data.0.ingredient.name'))->toBe('Oversold Cheese');
    expect($response->json('meta'))->toMatchArray([
        'total_value' => '0.175', 'negative_count' => 1, 'below_minimum_count' => 1, 'filter' => null,
    ]);
});

it('filters to low stock only', function (): void {
    $ctx = makeMerchantActor();
    p2StockFixture($ctx);

    $names = collect($this->getJson("/api/branches/{$ctx['branch']->uuid}/stock?filter=low")->assertOk()->json('data'))
        ->pluck('ingredient.name')->all();

    expect($names)->toBe(['Oversold Cheese', 'Never Stocked Salt']);
});

it('puts a low-stock card on the dashboard with counts per branch', function (): void {
    $ctx = makeMerchantActor();
    p2StockFixture($ctx);
    $quiet = Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Zz Quiet']);
    // The second branch never stocked anything: only the salt's minimum flags there.

    $card = $this->getJson('/api/dashboard/summary')->assertOk()->json('data.low_stock');

    expect($card['negative'])->toBe(1)
        ->and($card['below_minimum'])->toBe(3);
    $perBranch = collect($card['branches'])->keyBy('branch_uuid')->map(fn ($b) => [$b['negative'], $b['below_minimum']])->all();
    ksort($perBranch);
    $expected = [$ctx['branch']->uuid => [1, 1], $quiet->uuid => [0, 2]];
    ksort($expected);
    expect($perBranch)->toBe($expected);
});
