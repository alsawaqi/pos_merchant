<?php

declare(strict_types=1);

/**
 * Phase 7b-4 — Loss / Waste Report coverage (blueprint §5.11.5).
 *
 * Covers:
 *   - Headline total_value = SUM(quantity * unit_cost_at_time)
 *   - by_branch grouping with value + event count
 *   - by_reason grouping
 *   - top_wasted ingredients (limit 10) sorted by value
 *   - Date window scopes the data
 *   - Tenant isolation
 *   - Phase 8 shortfall stub note exposed
 */

use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('aggregates headline total_value from waste records', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // 2 kg * 1.500 = 3.000
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '2.000',
        'unit_cost_at_time' => '1.500',
        'reason' => WasteReason::Spoiled->value,
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    // 1 kg * 2.000 = 2.000
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '1.000',
        'unit_cost_at_time' => '2.000',
        'reason' => WasteReason::Expired->value,
        'occurred_at' => '2026-06-08 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.total_value'))->toBe('5.000');
    expect($response->json('data.headline.total_qty'))->toBe('3.000');
    expect($response->json('data.headline.event_count'))->toBe(2);
});

it('breaks down value by reason', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '4.000',
        'unit_cost_at_time' => '1.000',
        'reason' => WasteReason::Spoiled->value,
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '1.000',
        'unit_cost_at_time' => '1.000',
        'reason' => WasteReason::Expired->value,
        'occurred_at' => '2026-06-06 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $byReason = $response->json('data.by_reason');
    // Sorted by value DESC: spoiled (4.000) first, expired (1.000) second.
    expect($byReason)->toHaveCount(2);
    expect($byReason[0]['reason'])->toBe(WasteReason::Spoiled->value);
    expect($byReason[0]['value'])->toBe('4.000');
    expect($byReason[1]['reason'])->toBe(WasteReason::Expired->value);
    expect($byReason[1]['value'])->toBe('1.000');
});

it('breaks down value by branch', function (): void {
    $ctx = makeMerchantActor();
    $other = Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Branch B']);
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '2.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    WasteRecord::factory()->for($other, 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '5.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $byBranch = $response->json('data.by_branch');
    expect($byBranch)->toHaveCount(2);
    expect($byBranch[0]['branch_name'])->toBe('Branch B');
    expect($byBranch[0]['value'])->toBe('5.000');
    expect($byBranch[1]['branch_name'])->toBe($ctx['branch']->name);
    expect($byBranch[1]['value'])->toBe('2.000');
});

it('ranks top_wasted ingredients by value', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee Beans']);

    // Milk: low qty / high cost -> total 6.000
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '2.000',
        'unit_cost_at_time' => '3.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    // Beans: high qty / low cost -> total 5.000
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($beans, 'ingredient')->create([
        'quantity' => '5.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $top = $response->json('data.top_wasted');
    expect($top[0]['ingredient_name'])->toBe('Milk');
    expect($top[0]['value'])->toBe('6.000');
    expect($top[1]['ingredient_name'])->toBe('Coffee Beans');
    expect($top[1]['value'])->toBe('5.000');
});

it('respects the date window', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Outside the window — should be excluded.
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '99.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-05-15 12:00:00',
    ]);
    // Inside the window.
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '1.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.total_value'))->toBe('1.000');
    expect($response->json('data.headline.event_count'))->toBe(1);
});

it('does not leak other tenants waste records', function (): void {
    $ctx = makeMerchantActor();
    $foreign = Company::factory()->create();
    $fb = Branch::factory()->for($foreign, 'company')->create();
    $fi = Ingredient::factory()->for($foreign, 'company')->create();

    WasteRecord::factory()->for($fb, 'branch')->for($fi, 'ingredient')->create([
        'quantity' => '10.000',
        'unit_cost_at_time' => '5.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.total_value'))->toBe('0.000');
    expect($response->json('data.headline.event_count'))->toBe(0);
    expect($response->json('data.by_reason'))->toBe([]);
});

it('exposes the Phase 8 shortfall stub note', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/reports/loss-waste?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data._phase.shortfall_stub'))
        ->toContain('Phase 8');
});
