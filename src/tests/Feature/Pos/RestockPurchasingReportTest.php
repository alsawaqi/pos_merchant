<?php

declare(strict_types=1);

/**
 * Phase 7b-4 — Restock / Purchasing Report coverage (blueprint §5.11.6).
 *
 * Covers:
 *   - Headline total_cost = SUM(quantity * unit_cost_at_time)
 *     for Restock movements in window
 *   - by_supplier grouping derived from
 *     ingredient.primary_supplier_id (Unassigned bucket for null)
 *   - by_branch grouping
 *   - top_purchased ingredients (limit 20) sorted by cost
 *   - Date window scopes the data
 *   - Only Restock movements counted (Adjustment/Waste excluded)
 *   - Tenant isolation
 *   - Phase 9 invoice stub note exposed
 */

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('aggregates headline total_cost from Restock movements', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // 5 kg * 2.000 = 10.000
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '5.000',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    // 3 kg * 1.500 = 4.500
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '3.000',
        'unit_cost_at_time' => '1.500',
        'occurred_at' => '2026-06-08 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/restock-purchasing?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.total_cost'))->toBe('14.500');
    expect($response->json('data.headline.total_qty'))->toBe('8.000');
    expect($response->json('data.headline.event_count'))->toBe(2);
});

it('breaks down cost by supplier with Unassigned bucket for null', function (): void {
    $ctx = makeMerchantActor();
    $sup = Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'AlMashreq Trading']);
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'primary_supplier_id' => $sup->id,
    ]);
    $orphanIngredient = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'primary_supplier_id' => null,
    ]);

    // 10.000 OMR via the assigned supplier.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '5.000',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    // 3.000 OMR with no supplier.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($orphanIngredient, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '3.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-06 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/restock-purchasing?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $bySupplier = $response->json('data.by_supplier');
    expect($bySupplier)->toHaveCount(2);
    // Sorted by cost DESC.
    expect($bySupplier[0]['supplier_name'])->toBe('AlMashreq Trading');
    expect($bySupplier[0]['cost'])->toBe('10.000');
    expect($bySupplier[0]['supplier_id'])->toBe($sup->id);
    expect($bySupplier[1]['supplier_name'])->toBe('Unassigned');
    expect($bySupplier[1]['cost'])->toBe('3.000');
    expect($bySupplier[1]['supplier_id'])->toBeNull();
});

it('breaks down cost by branch', function (): void {
    $ctx = makeMerchantActor();
    $other = Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Branch B']);
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '2.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    StockMovement::factory()->for($other, 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '5.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/restock-purchasing?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $byBranch = $response->json('data.by_branch');
    expect($byBranch)->toHaveCount(2);
    expect($byBranch[0]['branch_name'])->toBe('Branch B');
    expect($byBranch[0]['cost'])->toBe('5.000');
    expect($byBranch[1]['branch_name'])->toBe($ctx['branch']->name);
    expect($byBranch[1]['cost'])->toBe('2.000');
});

it('ranks top_purchased ingredients by cost', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee Beans']);

    // Beans: low qty / high cost -> total 30.000
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($beans, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '2.000',
        'unit_cost_at_time' => '15.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    // Milk: high qty / low cost -> total 20.000
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '20.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/restock-purchasing?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $top = $response->json('data.top_purchased');
    expect($top[0]['ingredient_name'])->toBe('Coffee Beans');
    expect($top[0]['cost'])->toBe('30.000');
    expect($top[1]['ingredient_name'])->toBe('Milk');
    expect($top[1]['cost'])->toBe('20.000');
});

it('excludes non-Restock movement types', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Restock — counts.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '5.000',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);
    // Adjustment — must NOT count.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Adjustment->value,
        'quantity' => '1.000',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-06 12:00:00',
    ]);
    // Waste — must NOT count (lives in the Loss/Waste report).
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Waste->value,
        'quantity' => '-0.500',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-07 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/restock-purchasing?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.event_count'))->toBe(1);
    expect($response->json('data.headline.total_cost'))->toBe('10.000');
});

it('respects the date window', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Outside window — excluded.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '99.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-05-15 12:00:00',
    ]);
    // Inside window.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '4.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/restock-purchasing?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.total_cost'))->toBe('4.000');
    expect($response->json('data.headline.event_count'))->toBe(1);
});

it('does not leak other tenants Restock movements', function (): void {
    $ctx = makeMerchantActor();
    $foreign = Company::factory()->create();
    $fb = Branch::factory()->for($foreign, 'company')->create();
    $fi = Ingredient::factory()->for($foreign, 'company')->create();

    StockMovement::factory()->for($fb, 'branch')->for($fi, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '100.000',
        'unit_cost_at_time' => '5.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/restock-purchasing?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.headline.total_cost'))->toBe('0.000');
    expect($response->json('data.by_supplier'))->toBe([]);
});

it('exposes the Phase 9 invoice stub note', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/reports/restock-purchasing?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data._phase.invoice_stub'))
        ->toContain('Phase 9');
});
