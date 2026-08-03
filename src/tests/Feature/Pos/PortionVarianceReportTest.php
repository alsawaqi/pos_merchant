<?php

declare(strict_types=1);

/**
 * Portion Variance Report — the honest decomposition of "where did the
 * food go", in qty AND frozen cost.
 *
 * Covers:
 *   - Bucket decomposition: theoretical (sale+addon) / explained waste /
 *     count variance / manual adjustments — with nothing double-counted
 *   - reconciliation_variance waste rows stay OUT of the waste bucket
 *     (they surface as count variance via the count lines)
 *   - Count-linked adjustment movements stay OUT of the manual bucket
 *   - Logistics rows (transfer_out) never enter any bucket
 *   - Costs come from frozen unit_cost_at_time, not current ingredient cost
 *   - variance_pct + headline totals + uncounted-coverage counter
 *   - Branch filter, window filter, tenant isolation, permission gate
 */

use App\Enums\StockMovementType;
use App\Enums\WasteReason;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** One sale-consumption movement (negative qty, frozen unit cost). */
function pvSeedSale(array $ctx, Ingredient $ing, string $qty, string $unitCost, string $at = '2026-06-03 12:00:00'): void
{
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($ing, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => $qty,
        'unit_cost_at_time' => $unitCost,
        'occurred_at' => $at,
    ]);
}

/** A day-end count with one line carrying the given signed variance. */
function pvSeedCount(array $ctx, Ingredient $ing, string $varianceUnits, string $unitCost, string $at = '2026-06-05 22:00:00', ?int $stockMovementId = null): StockCount
{
    $count = StockCount::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'counted_at' => $at,
    ]);
    StockCountLine::query()->create([
        'stock_count_id' => $count->id,
        'ingredient_id' => $ing->id,
        'counted_pieces' => null,
        'counted_units' => '0.000',
        'expected_units' => '0.000',
        'variance_units' => $varianceUnits,
        'unit_cost_at_time' => $unitCost,
        'stock_movement_id' => $stockMovementId,
    ]);

    return $count;
}

it('decomposes theoretical, waste, count variance and manual adjustments without double counting', function (): void {
    $ctx = makeMerchantActor();
    $chicken = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Chicken', 'unit' => 'kg']);

    // Theoretical: 2.000 + 1.000 kg at 2.000 OMR/kg frozen = 6.000 OMR.
    pvSeedSale($ctx, $chicken, '-2.000', '2.000', '2026-06-02 12:00:00');
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($chicken, 'ingredient')->create([
        'movement_type' => StockMovementType::AddOnConsumption->value,
        'quantity' => '-1.000',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-03 12:00:00',
    ]);

    // Explained waste: 0.500 kg spoilage at 2.000 = 1.000 OMR.
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($chicken, 'ingredient')->create([
        'quantity' => '0.500',
        'unit_cost_at_time' => '2.000',
        'reason' => WasteReason::Spoiled->value,
        'occurred_at' => '2026-06-04 12:00:00',
    ]);

    // Day-end count found 0.750 kg MISSING (at 2.000 = -1.500 OMR). The
    // count also wrote its reconciliation_variance waste row + movement —
    // both must stay out of the waste / manual-adjustment buckets.
    $reconMovement = StockMovement::factory()->for($ctx['branch'], 'branch')->for($chicken, 'ingredient')->create([
        'movement_type' => StockMovementType::Waste->value,
        'quantity' => '-0.750',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-05 22:00:00',
    ]);
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($chicken, 'ingredient')->create([
        'quantity' => '0.750',
        'unit_cost_at_time' => '2.000',
        'reason' => WasteReason::ReconciliationVariance->value,
        'occurred_at' => '2026-06-05 22:00:00',
    ]);
    pvSeedCount($ctx, $chicken, '-0.750', '2.000', '2026-06-05 22:00:00', $reconMovement->id);

    // Manual adjustment (no count link): -0.250 kg at 2.000 = -0.500 OMR.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($chicken, 'ingredient')->create([
        'movement_type' => StockMovementType::Adjustment->value,
        'quantity' => '-0.250',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-06 12:00:00',
    ]);

    // Logistics: a transfer OUT must not appear in any bucket.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($chicken, 'ingredient')->create([
        'movement_type' => StockMovementType::TransferOut->value,
        'quantity' => '-4.000',
        'unit_cost_at_time' => '2.000',
        'occurred_at' => '2026-06-06 13:00:00',
    ]);

    $row = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows.0');

    expect($row['ingredient_name'])->toBe('Chicken');
    expect($row['theoretical_qty'])->toBe('3.000');
    expect($row['theoretical_cost'])->toBe('6.000');
    expect($row['waste_qty'])->toBe('0.500');
    expect($row['waste_cost'])->toBe('1.000');
    expect($row['count_variance_qty'])->toBe('-0.750');
    expect($row['count_variance_cost'])->toBe('-1.500');
    expect($row['adjustment_qty'])->toBe('-0.250');
    expect($row['adjustment_cost'])->toBe('-0.500');
    // -0.750 missing / 3.000 theoretical = -25.0%
    expect($row['variance_pct'])->toBe('-25.0');
    expect($row['count_events'])->toBe(1);
    expect($row['last_counted_at'])->not->toBeNull();
});

it('excludes count-linked overage adjustments from the manual bucket', function (): void {
    $ctx = makeMerchantActor();
    $rice = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Rice']);

    pvSeedSale($ctx, $rice, '-1.000', '0.400');

    // Count OVERAGE: +0.300 written as a count-linked adjustment movement.
    $overageMovement = StockMovement::factory()->for($ctx['branch'], 'branch')->for($rice, 'ingredient')->create([
        'movement_type' => StockMovementType::Adjustment->value,
        'quantity' => '0.300',
        'unit_cost_at_time' => '0.400',
        'occurred_at' => '2026-06-05 22:00:00',
    ]);
    pvSeedCount($ctx, $rice, '0.300', '0.400', '2026-06-05 22:00:00', $overageMovement->id);

    $row = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows.0');

    // The overage lives in count_variance ONLY; the manual bucket is empty.
    expect($row['count_variance_qty'])->toBe('0.300');
    expect($row['adjustment_qty'])->toBe('0.000');
    expect($row['adjustment_cost'])->toBe('0.000');
});

it('uses the frozen unit_cost_at_time, not the current ingredient cost', function (): void {
    $ctx = makeMerchantActor();
    // Current default cost is wildly different from the frozen event cost.
    $saffron = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Saffron', 'default_unit_cost' => '99.000']);

    pvSeedSale($ctx, $saffron, '-2.000', '1.250');

    $row = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows.0');

    expect($row['theoretical_cost'])->toBe('2.500'); // 2 x 1.250 frozen
});

it('computes the headline totals and the uncounted coverage counter', function (): void {
    $ctx = makeMerchantActor();
    $counted = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Counted']);
    $blind = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Blind']);

    pvSeedSale($ctx, $counted, '-1.000', '3.000');
    pvSeedSale($ctx, $blind, '-1.000', '1.000');
    // Counted ingredient: 0.200 missing at 3.000 = 0.600 shortfall.
    pvSeedCount($ctx, $counted, '-0.200', '3.000');

    $headline = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.headline');

    expect($headline['theoretical_cost'])->toBe('4.000');
    expect($headline['count_shortfall_cost'])->toBe('0.600');
    expect($headline['count_overage_cost'])->toBe('0.000');
    // 'Blind' consumed but never counted in the window.
    expect($headline['uncounted_ingredients'])->toBe(1);
});

it('scopes by branch_ids and rejects out-of-scope branches', function (): void {
    $ctx = makeMerchantActor();
    $other = App\Models\Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'B2']);
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);

    pvSeedSale($ctx, $milk, '-1.000', '1.000');
    StockMovement::factory()->for($other, 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-5.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-03 12:00:00',
    ]);

    $row = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10&branch_ids[]='.$ctx['branch']->id)
        ->assertOk()->json('data.rows.0');
    expect($row['theoretical_qty'])->toBe('1.000');

    // A foreign company's branch id: the unrestricted super admin is not
    // branch-clamped, but the company join keeps the data invisible.
    $foreign = App\Models\Branch::factory()->for(Company::factory()->create(), 'company')->create();
    $data = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10&branch_ids[]='.$foreign->id)
        ->assertOk()->json('data');
    expect($data['rows'])->toBe([]);
});

it('filters by the window on occurred_at and counted_at', function (): void {
    $ctx = makeMerchantActor();
    $tea = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Tea']);

    pvSeedSale($ctx, $tea, '-1.000', '1.000', '2026-05-20 12:00:00'); // before window
    pvSeedCount($ctx, $tea, '-0.100', '1.000', '2026-05-20 22:00:00'); // before window

    $data = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data');
    expect($data['rows'])->toBe([]);
});

it('nets void reversals out of the theoretical bucket', function (): void {
    $ctx = makeMerchantActor();
    $beef = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Beef']);

    // A paid order consumed 2kg... then was voided: the device writes a
    // POSITIVE reversal row of the same movement type. Net usage = 0 —
    // counting the voided sale as "usage" would be phantom theoretical
    // that no day-end count could ever match.
    pvSeedSale($ctx, $beef, '-2.000', '3.000', '2026-06-03 10:00:00');
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($beef, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '2.000', // void reversal
        'unit_cost_at_time' => '3.000',
        'occurred_at' => '2026-06-03 10:05:00',
    ]);
    // A second, real sale that stays.
    pvSeedSale($ctx, $beef, '-1.000', '3.000', '2026-06-03 11:00:00');

    $row = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows.0');

    expect($row['theoretical_qty'])->toBe('1.000');
    expect($row['theoretical_cost'])->toBe('3.000');
});

it('reports kitchen-production usage and counts production-only ingredients as coverage-blind', function (): void {
    $ctx = makeMerchantActor();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Flour']);

    // Consumed ONLY via kitchen production (cooked products): batch start
    // -5kg, a cancelled batch returned +1kg. Net production usage = 4kg.
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($flour, 'ingredient')->create([
        'movement_type' => StockMovementType::ProductionConsumption->value,
        'quantity' => '-5.000',
        'unit_cost_at_time' => '0.500',
        'occurred_at' => '2026-06-03 08:00:00',
    ]);
    StockMovement::factory()->for($ctx['branch'], 'branch')->for($flour, 'ingredient')->create([
        'movement_type' => StockMovementType::ProductionReturn->value,
        'quantity' => '1.000',
        'unit_cost_at_time' => '0.500',
        'occurred_at' => '2026-06-03 09:00:00',
    ]);

    $data = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data');

    $row = $data['rows'][0];
    expect($row['production_qty'])->toBe('4.000');
    expect($row['production_cost'])->toBe('2.000');
    expect($row['theoretical_qty'])->toBe('0.000');
    // Production-consumed but never counted → the coverage counter sees it.
    expect($data['headline']['uncounted_ingredients'])->toBe(1);
});

it('applies the P-G5 branch clamp for branch-scoped users', function (): void {
    $ctx = makeMerchantActor(App\Enums\MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $branchB = App\Models\Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'B2']);

    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    pvSeedSale($ctx, $milk, '-1.000', '1.000');
    // Same-company data at the OTHER branch, across all buckets.
    StockMovement::factory()->for($branchB, 'branch')->for($milk, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-7.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-03 12:00:00',
    ]);
    WasteRecord::factory()->for($branchB, 'branch')->for($milk, 'ingredient')->create([
        'quantity' => '2.000',
        'unit_cost_at_time' => '1.000',
        'reason' => WasteReason::Spoiled->value,
        'occurred_at' => '2026-06-04 12:00:00',
    ]);
    $countB = StockCount::query()->create([
        'company_id' => $ctx['company']->id, 'branch_id' => $branchB->id,
        'counted_at' => '2026-06-05 22:00:00',
    ]);
    StockCountLine::query()->create([
        'stock_count_id' => $countB->id, 'ingredient_id' => $milk->id,
        'counted_pieces' => null, 'counted_units' => '0.000',
        'expected_units' => '0.500', 'variance_units' => '-0.500',
        'unit_cost_at_time' => '1.000',
    ]);

    // No explicit branch filter: the clamp restricts to the user's scope.
    $row = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data.rows.0');
    expect($row['theoretical_qty'])->toBe('1.000');
    expect($row['waste_qty'])->toBe('0.000');
    expect($row['count_variance_qty'])->toBe('0.000');

    // Requesting the out-of-scope branch explicitly → 403.
    $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10&branch_ids[]='.$branchB->id)
        ->assertForbidden();
});

it('is invisible across tenants', function (): void {
    $ctx = makeMerchantActor();
    $foreignCompany = Company::factory()->create();
    $foreignBranch = App\Models\Branch::factory()->for($foreignCompany, 'company')->create();
    $foreignIng = Ingredient::factory()->for($foreignCompany, 'company')->create(['name' => 'Foreign']);

    StockMovement::factory()->for($foreignBranch, 'branch')->for($foreignIng, 'ingredient')->create([
        'movement_type' => StockMovementType::SaleConsumption->value,
        'quantity' => '-9.000',
        'unit_cost_at_time' => '1.000',
        'occurred_at' => '2026-06-03 12:00:00',
    ]);
    // The waste and count buckets scope independently — cover them too.
    WasteRecord::factory()->for($foreignBranch, 'branch')->for($foreignIng, 'ingredient')->create([
        'quantity' => '1.000',
        'unit_cost_at_time' => '1.000',
        'reason' => WasteReason::Spoiled->value,
        'occurred_at' => '2026-06-03 12:00:00',
    ]);
    $foreignCount = StockCount::query()->create([
        'company_id' => $foreignCompany->id, 'branch_id' => $foreignBranch->id,
        'counted_at' => '2026-06-05 22:00:00',
    ]);
    StockCountLine::query()->create([
        'stock_count_id' => $foreignCount->id, 'ingredient_id' => $foreignIng->id,
        'counted_pieces' => null, 'counted_units' => '0.000',
        'expected_units' => '1.000', 'variance_units' => '-1.000',
        'unit_cost_at_time' => '1.000',
    ]);

    $data = $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertOk()->json('data');
    expect($data['rows'])->toBe([]);
});

it('requires the reports.view permission', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson('/api/reports/portion-variance?date_from=2026-06-01&date_to=2026-06-10')
        ->assertForbidden();
});

it('exports as csv via the shared export route', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    pvSeedSale($ctx, $milk, '-1.000', '1.000');

    $this->get('/api/reports/portion-variance/export?date_from=2026-06-01&date_to=2026-06-10&format=csv', ['Accept' => 'application/json'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});
