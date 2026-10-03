<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 fix order 1, K4 — a prep waste is ONE event that names the prep.
 *
 * "1 L of tomato sauce thrown away" writes one waste record per raw
 * ingredient behind it. Every record now carries the prep item and a shared
 * waste_group_uuid, and Loss & Waste counts one event per group and lists the
 * prep items wasted by name. Before: a 5-component sauce was 5 events and the
 * sauce was never named.
 */

use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('counts one 5-component sauce waste as one event and names the sauce in Loss & Waste', function (): void {
    $ctx = makeMerchantActor();
    $parts = [];
    foreach (['Tomato' => '1500', 'Oil' => '100', 'Garlic' => '40', 'Salt' => '20', 'Basil' => '10'] as $name => $perBatch) {
        $ingredient = p3Ingredient($ctx['company'], $name, 'g', '0.002');
        p3Stock($ctx['branch'], $ingredient, '10000');
        $parts[] = [$ingredient, $perBatch];
    }
    $sauce = p3Prep($ctx['company'], 'Tomato sauce', 'ml', '2000', $parts);
    $flour = p3Ingredient($ctx['company'], 'Flour', 'g', '0.0005');
    p3Stock($ctx['branch'], $flour, '5000');
    $day = now()->toDateString();

    $prep = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $sauce->uuid, 'quantity' => '1000', 'reason' => 'spoiled', 'occurred_at' => now()->toIso8601String(),
    ])->assertCreated();
    // A plain ingredient waste next to it is its own event.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $flour->uuid, 'quantity' => '200', 'reason' => 'spoiled', 'occurred_at' => now()->toIso8601String(),
    ])->assertCreated();

    // Five records, one group, each naming the sauce.
    $group = $prep->json('waste_group_uuid');
    expect(WasteRecord::query()->where('waste_group_uuid', $group)->count())->toBe(5)
        ->and(WasteRecord::query()->where('prep_ingredient_id', $sauce->id)->count())->toBe(5)
        ->and($prep->json('records.0.prep_item.name'))->toBe('Tomato sauce');

    $report = $this->getJson("/api/reports/loss-waste?date_from={$day}&date_to={$day}")->assertOk();

    expect($report->json('data.headline.event_count'))->toBe(2)
        ->and($report->json('data.by_reason.0.event_count'))->toBe(2)
        ->and($report->json('data.by_branch.0.event_count'))->toBe(2);
    $sauceRow = $report->json('data.prep_wastes.0');
    // 1000 ml of a 2000 ml batch: 835 g of components at 0.002 = 1.670.
    expect($report->json('data.prep_wastes'))->toHaveCount(1)
        ->and($sauceRow['prep_name'])->toBe('Tomato sauce')
        ->and($sauceRow['event_count'])->toBe(1)
        ->and($sauceRow['value'])->toBe('1.670');

    // The Waste tab names the prep item on each of its records.
    $list = $this->getJson("/api/branches/{$ctx['branch']->uuid}/waste")->assertOk()->json('data');
    expect(collect($list)->where('waste_group_uuid', $group)->pluck('prep_item.name')->unique()->values()->all())->toBe(['Tomato sauce']);
});
