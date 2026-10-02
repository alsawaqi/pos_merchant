<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 P3-4 — the ONE explode / cost helper (App\Support\Recipes\PrepGraph).
 *
 * The data contract shared with pos_api: a line using prep item P with
 * quantity q becomes, for each component c, q × c.quantity ÷ P.yield of c,
 * repeated until only raw ingredients remain, lines of one raw ingredient
 * merged; P costs Σ(c.quantity × cost(c)) ÷ P.yield per base unit,
 * recursively; 4-decimal quantities and 6-decimal costs, rounded only at the
 * end. Components may be prep items up to 3 levels deep; cycles are refused.
 */

use App\Support\Recipes\PrepGraph;
use App\Support\Recipes\PrepRecipeException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/**
 * Raw: 1 tomato g 0.002, 2 oil ml 0.004, 3 salt g 0.0003, 4 flour g 0.0005.
 * Prep 10 sauce (2000 ml): 1500 tomato + 100 oil + 10 salt.
 * Prep 20 pizza base (10 pieces): 1000 flour + 500 sauce + 3 salt.
 */
function p3Graph(array $extra = []): PrepGraph
{
    return new PrepGraph(
        $extra + [
            10 => ['yield' => '2000', 'lines' => [1 => '1500', 2 => '100', 3 => '10']],
            20 => ['yield' => '10', 'lines' => [4 => '1000', 10 => '500', 3 => '3']],
        ],
        [1 => '0.002', 2 => '0.004', 3 => '0.0003', 4 => '0.0005'],
        [1 => 'Tomato', 2 => 'Oil', 3 => 'Salt', 4 => 'Flour', 10 => 'Sauce', 20 => 'Pizza base', 30 => 'Pizza kit', 40 => 'Pizza box'],
    );
}

it('explodes a prep line into its raw ingredients by the contract rule', function (): void {
    // 100 ml of sauce = 100 × (1500, 100, 10) ÷ 2000.
    expect(p3Graph()->explode([10 => '100']))->toBe([1 => '75.000', 2 => '5.000', 3 => '0.500']);
});

it('explodes nested prep items and merges lines of the same raw ingredient', function (): void {
    // 1 pizza base (of 10/batch): flour 100, salt 0.3 own + sauce 50 ml -> tomato 37.5, oil 2.5, salt 0.25.
    // Plus a direct 1 g of salt on the same dish: salt merges to 0.3 + 0.25 + 1.
    expect(p3Graph()->explode([20 => '1', 3 => '1']))->toBe([
        4 => '100.000',
        1 => '37.500',
        2 => '2.500',
        3 => '1.550',
    ]);
});

it('keeps exact quantities until the end and rounds them to 4 decimals once', function (): void {
    // 1 ml of sauce: tomato 0.75, oil 0.05, salt 0.005 — 3 g of salt per 2000 ml would be 0.0015.
    $graph = new PrepGraph(
        [10 => ['yield' => '3', 'lines' => [3 => '1']]],
        [3 => '0.0003'],
    );
    // 1 ÷ 3 = 0.33333… per unit; × 3 units would be exactly 1 if never rounded in between.
    expect($graph->explode([10 => '3']))->toBe([3 => '1.000'])
        ->and($graph->explode([10 => '1']))->toBe([3 => '0.3333'])
        // A component amount that rounds to 0 at 4 decimals is dropped.
        ->and($graph->explode([10 => '0.0001']))->toBe([]);
});

it('costs a prep item per base unit and per batch through its recipe, recursively', function (): void {
    $graph = p3Graph();
    // Sauce batch: 1500 × 0.002 + 100 × 0.004 + 10 × 0.0003 = 3.403 → per ml 0.0017015,
    // shown at 6 decimals (0.001702) but never rounded inside the next level up:
    expect($graph->unitCost(10))->toBe('0.001702')
        ->and((string) $graph->unitCostExact(10)->toScale(7))->toBe('0.0017015')
        ->and((string) $graph->batchCostExact(10)->toScale(6))->toBe('3.403000');
    // Pizza base batch: 1000 × 0.0005 + 500 × 0.0017015 + 3 × 0.0003 = 1.35165 → per piece 0.135165
    // (with the rounded 0.001702 it would be 1.3519 → 0.13519).
    expect($graph->unitCost(20))->toBe('0.135165');
    // A raw ingredient costs its own weighted average.
    expect($graph->unitCost(1))->toBe('0.002');
    // Cost of lines = Σ quantity × unit cost (prep lines through their recipe).
    expect((string) $graph->linesCostExact([20 => '2', 1 => '10'])->toScale(6))->toBe('0.290330');
});

it('agrees with the explode rule: a prep line costs what its exploded raw lines cost', function (): void {
    $graph = p3Graph();
    $viaPrep = $graph->linesCostExact([20 => '4']);
    $viaRaw = $graph->linesCostExact($graph->explode([20 => '4']));
    expect((string) $viaPrep->toScale(6))->toBe((string) $viaRaw->toScale(6));
});

it('measures nesting depth and allows 3 prep levels', function (): void {
    $graph = p3Graph([
        30 => ['yield' => '1', 'lines' => [20 => '1']],
    ]);
    expect($graph->depth(1))->toBe(0)
        ->and($graph->depth(10))->toBe(1)
        ->and($graph->depth(20))->toBe(2)
        ->and($graph->depth(30))->toBe(3);
    $graph->assertValid();
});

it('refuses a 4th prep level with a message naming the chain', function (): void {
    $graph = p3Graph([
        30 => ['yield' => '1', 'lines' => [20 => '1']],
        40 => ['yield' => '1', 'lines' => [30 => '1']],
    ]);
    expect(fn () => $graph->assertValid())
        ->toThrow(PrepRecipeException::class, 'Prep items can be nested at most 3 levels deep: Pizza box → Pizza kit → Pizza base → Sauce would be 4.');
});

it('refuses cycles, direct and indirect, when costing, exploding or validating', function (): void {
    $loop = p3Graph()->withRecipe(10, '2000', [1 => '1500', 20 => '1']);
    expect(fn () => $loop->assertValid())->toThrow(PrepRecipeException::class, 'A prep item cannot use itself, directly or through another prep item: Sauce → Pizza base → Sauce.')
        ->and(fn () => $loop->explode([20 => '1']))->toThrow(PrepRecipeException::class)
        ->and(fn () => $loop->unitCostExact(10))->toThrow(PrepRecipeException::class);

    $self = p3Graph()->withRecipe(10, '2000', [10 => '1']);
    expect(fn () => $self->assertValid())->toThrow(PrepRecipeException::class);
});

it('refuses to explode or cost a prep item without a yield', function (): void {
    $graph = new PrepGraph([10 => ['yield' => null, 'lines' => [1 => '5']]], [1 => '0.002'], [10 => 'Sauce']);
    expect(fn () => $graph->explode([10 => '1']))->toThrow(PrepRecipeException::class, 'Prep item "Sauce" has no yield');
});

it('loads one company graph from the tables, never another company\'s', function (): void {
    $ctx = makeMerchantActor();
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $sauce = p3Prep($ctx['company'], 'Sauce', 'ml', '2000', [[$tomato, '1500']]);
    $other = makeMerchantActor();
    $foreign = p3Ingredient($other['company'], 'Foreign', 'g', '9');

    $graph = PrepGraph::load($ctx['company']->id);
    expect($graph->isPrep($sauce->id))->toBeTrue()
        ->and($graph->isPrep($tomato->id))->toBeFalse()
        ->and($graph->explode([$sauce->id => '200']))->toBe([$tomato->id => '150.000'])
        ->and($graph->unitCost($sauce->id))->toBe('0.0015')
        ->and($graph->unitCost($foreign->id))->toBe('0.000');
});
