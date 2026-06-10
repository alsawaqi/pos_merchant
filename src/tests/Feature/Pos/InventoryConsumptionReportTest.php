<?php

declare(strict_types=1);

/**
 * Phase 7b-4 — Inventory Consumption Report coverage (blueprint §5.11.3).
 *
 * Covers:
 *   - Aggregates negative stock movements as "consumed"
 *   - days_of_stock derived from current balance / per-day rate
 *   - below_min_threshold flag for low stock
 *   - Branch filter scopes the consumption AND the balance
 *   - Tenant isolation (foreign company ingredients invisible)
 *   - Empty window returns zero rows
 */

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('aggregates consumption from negative stock movements', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Milk', 'min_stock_threshold' => '2.000']);

    // 10 kg current balance.
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')
        ->create(['quantity' => '10.000']);

    // Two consumption events totalling -3 kg over a 10-day window
    // (date_from = 2026-06-01, date_to = 2026-06-10 -> 10 days).
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-2.000',
        'occurred_at' => '2026-06-03 12:00:00',
    ]);
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-1.000',
        'occurred_at' => '2026-06-07 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['ingredient_name'])->toBe('Milk');
    expect($rows[0]['consumed'])->toBe('3.000');
    expect($rows[0]['current_balance'])->toBe('10.000');
    // 3 kg / 10 days = 0.3 / day
    expect($rows[0]['consumption_per_day'])->toBe('0.300');
    // 10 / 0.3 = 33.33 days
    expect($rows[0]['days_of_stock'])->toBe(33.33);
    expect($rows[0]['below_min_threshold'])->toBeFalse();
});

it('flags ingredients below their min_stock_threshold', function (): void {
    $ctx = makeMerchantActor();
    $beans = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Beans', 'min_stock_threshold' => '5.000']);

    // Balance is below threshold (3 < 5).
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($beans, 'ingredient')
        ->create(['quantity' => '3.000']);

    StockMovement::factory()->for($ctx['branch'], 'branch')->for($beans, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-0.500',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows[0]['below_min_threshold'])->toBeTrue();
});

it('returns days_of_stock null when there is no consumption', function (): void {
    $ctx = makeMerchantActor();
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Sugar']);

    BranchStock::factory()->for($ctx['branch'], 'branch')->for($sugar, 'ingredient')
        ->create(['quantity' => '20.000']);

    // Only POSITIVE movements (a Restock) in the window -- not
    // consumption, so the row should not appear at all.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($sugar, 'ingredient')->create([
        'movement_type' => StockMovementType::Restock->value,
        'quantity' => '5.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk();

    expect($response->json('data.rows'))->toBe([]);
});

it('filters by branch_ids when provided', function (): void {
    $ctx = makeMerchantActor();
    $otherBranch = Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Branch B']);

    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);

    // Consumption at primary branch.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-1.000',
        'occurred_at' => '2026-06-03 12:00:00',
    ]);
    // Consumption at other branch — should be filtered out.
    StockMovement::factory()->for($otherBranch, 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-5.000',
        'occurred_at' => '2026-06-03 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10&branch_ids[]='.$ctx['branch']->id)
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['consumed'])->toBe('1.000');
});

it('does not leak other tenants ingredients', function (): void {
    $ctx = makeMerchantActor();
    $foreign = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($foreign, 'company')->create();
    $foreignIngredient = Ingredient::factory()->for($foreign, 'company')->create(['name' => 'Foreign Salt']);

    StockMovement::factory()->for($foreignBranch, 'branch')->for($foreignIngredient, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-3.000',
        'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $response = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk();

    expect($response->json('data.rows'))->toBe([]);
});

it('flags an ingredient consumed >20% above its trailing-30-day average', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create(['quantity' => '100.000']);

    // Trailing baseline: 30 over the 30 days before the window = 1.0/day.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-30.000', 'occurred_at' => '2026-05-15 12:00:00',
    ]);
    // Window: 20 over 10 days = 2.0/day = 2× baseline (> 1.2×) → anomaly.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-20.000', 'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $row = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows.0');
    expect($row['anomaly'])->toBeTrue();
    expect($row['trailing_avg_per_day'])->toBe('1.000');
});

it('does not flag consumption within the trailing baseline', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create(['quantity' => '100.000']);

    // Baseline 30/30d = 1.0/day; window 8/10d = 0.8/day (< 1.2×) → no anomaly.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-30.000', 'occurred_at' => '2026-05-15 12:00:00',
    ]);
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-8.000', 'occurred_at' => '2026-06-05 12:00:00',
    ]);

    $row = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows.0');
    expect($row['anomaly'])->toBeFalse();
});

// =================== Phase A — day-end count columns (Additions §2.11) ====

it('joins the window day-end counts as counted_units + net variance_units', function (): void {
    $ctx = makeMerchantActor();
    $milk = App\Models\Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create(['quantity' => '6.000']);

    StockMovement::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-2.000', 'occurred_at' => '2026-06-03 12:00:00',
    ]);

    // Two counts in window: first wrote -1.000 variance, the second -0.500;
    // counted_units must be the LAST count's value, variance the SUM.
    $count1 = App\Models\StockCount::query()->create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'counted_at' => '2026-06-04 22:00:00',
    ]);
    App\Models\StockCountLine::query()->create([
        'stock_count_id' => $count1->id, 'ingredient_id' => $milk->id,
        'counted_pieces' => null, 'counted_units' => '7.000',
        'expected_units' => '8.000', 'variance_units' => '-1.000',
        'unit_cost_at_time' => '1.500',
    ]);
    $count2 = App\Models\StockCount::query()->create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'counted_at' => '2026-06-08 22:00:00',
    ]);
    App\Models\StockCountLine::query()->create([
        'stock_count_id' => $count2->id, 'ingredient_id' => $milk->id,
        'counted_pieces' => '6.000', 'counted_units' => '6.000',
        'expected_units' => '6.500', 'variance_units' => '-0.500',
        'unit_cost_at_time' => '1.500',
    ]);

    $row = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows.0');

    expect($row['counted_units'])->toBe('6.000');
    expect($row['variance_units'])->toBe('-1.500');
    expect($row['last_counted_at'])->not->toBeNull();
});

it('appends counted-but-unconsumed ingredients as zero-consumption rows', function (): void {
    $ctx = makeMerchantActor();
    $sugar = App\Models\Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Sugar']);
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($sugar, 'ingredient')->create(['quantity' => '4.000']);

    $count = App\Models\StockCount::query()->create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'counted_at' => '2026-06-05 22:00:00',
    ]);
    App\Models\StockCountLine::query()->create([
        'stock_count_id' => $count->id, 'ingredient_id' => $sugar->id,
        'counted_pieces' => null, 'counted_units' => '4.000',
        'expected_units' => '5.000', 'variance_units' => '-1.000',
        'unit_cost_at_time' => '0.300',
    ]);

    $rows = $this->getJson('/api/reports/inventory-consumption?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['ingredient_name'])->toBe('Sugar');
    expect($rows[0]['consumed'])->toBe('0.000');
    expect($rows[0]['variance_units'])->toBe('-1.000');
});
