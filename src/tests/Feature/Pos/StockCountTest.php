<?php

declare(strict_types=1);

/**
 * Feature tests for Phase A SubmitStockCountAction + StockCountsController
 * (Additions doc §2.8 — day-end reconciliation).
 *
 * The doc's worked example (milk): balance 7.000 L, staff count 6
 * bottles (ratio 1 L/bottle) → counted 6.000, variance −1.000 → a
 * waste movement with reason reconciliation_variance, so the
 * Loss/Waste report sees it with zero extra wiring.
 *
 * Variance polarity:
 *   counted < expected → WasteRecord(reconciliation_variance) + waste movement
 *   counted > expected → positive Adjustment movement
 *   counted = expected → line recorded, NO movement
 */

use App\Enums\MerchantRole;
use App\Enums\StockMovementType;
use App\Enums\WasteReason;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedBalance(array $ctx, Ingredient $ingredient, string $qty): void
{
    BranchStock::factory()
        ->for($ctx['branch'], 'branch')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => $qty]);
}

// =================== SHORTFALL (the doc's milk example) ===================

it('reconciles a shortfall into a reconciliation_variance waste movement', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Whole Milk',
        'unit' => 'l',
        'piece_unit_label' => 'bottle',
        'units_per_piece' => '1.0000',
        'default_unit_cost' => '1.500',
    ]);
    seedBalance($ctx, $milk, '7.000');

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'counted_pieces' => 6],
        ],
    ])->assertCreated();

    // Header + line stored with the frozen expected/variance.
    $count = StockCount::query()->where('branch_id', $ctx['branch']->id)->firstOrFail();
    $line = StockCountLine::query()->where('stock_count_id', $count->id)->firstOrFail();
    expect((string) $line->counted_pieces)->toBe('6.000');
    expect((string) $line->counted_units)->toBe('6.000');
    expect((string) $line->expected_units)->toBe('7.000');
    expect((string) $line->variance_units)->toBe('-1.000');
    expect((string) $line->unit_cost_at_time)->toBe('1.500');

    // The shortfall became a WasteRecord with the dedicated reason…
    $waste = WasteRecord::query()->where('ingredient_id', $milk->id)->firstOrFail();
    expect($waste->reason)->toBe(WasteReason::ReconciliationVariance);
    expect((string) $waste->quantity)->toBe('1.000');

    // …with the signed-negative waste movement linked on the line.
    $movement = StockMovement::query()->findOrFail($line->stock_movement_id);
    expect($movement->movement_type)->toBe(StockMovementType::Waste);
    expect((string) $movement->quantity)->toBe('-1.000');

    // Balance now matches the physical count.
    $balance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $milk->id)
        ->firstOrFail();
    expect((string) $balance->quantity)->toBe('6.000');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.stock_count.submitted',
        'auditable_id' => $count->id,
        'branch_id' => $ctx['branch']->id,
    ]);

    expect($response->json('data.lines.0.variance_units'))->toBe('-1.000');
    expect($response->json('data.lines.0.variance_value'))->toBe('-1.500');
});

// =================== OVERAGE ===================

it('reconciles an overage into a positive adjustment movement', function (): void {
    $ctx = makeMerchantActor();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Flour',
        'unit' => 'kg',
        'default_unit_cost' => '0.300',
    ]);
    seedBalance($ctx, $flour, '5.000');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [
            ['ingredient_uuid' => $flour->uuid, 'counted_units' => '6.500'],
        ],
    ])->assertCreated();

    $line = StockCountLine::query()->firstOrFail();
    expect((string) $line->variance_units)->toBe('1.500');

    $movement = StockMovement::query()->findOrFail($line->stock_movement_id);
    expect($movement->movement_type)->toBe(StockMovementType::Adjustment);
    expect((string) $movement->quantity)->toBe('1.500');

    // No waste record on an overage.
    expect(WasteRecord::query()->count())->toBe(0);

    $balance = BranchStock::query()->where('ingredient_id', $flour->id)->firstOrFail();
    expect((string) $balance->quantity)->toBe('6.500');
});

// =================== EXACT MATCH ===================

it('records a clean line with no movement when the count matches', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Milk', 'unit' => 'l',
    ]);
    seedBalance($ctx, $milk, '4.000');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'counted_units' => '4.000'],
        ],
    ])->assertCreated();

    $line = StockCountLine::query()->firstOrFail();
    expect((string) $line->variance_units)->toBe('0.000');
    expect($line->stock_movement_id)->toBeNull();
    expect(StockMovement::query()->count())->toBe(0);
});

// =================== MULTI-LINE ATOMICITY ===================

it('rejects the whole count when one line is invalid (atomic)', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Milk', 'unit' => 'l',
    ]);
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Flour', 'unit' => 'kg',
    ]);
    seedBalance($ctx, $milk, '5.000');

    // flour counted in PIECES but has no piece ratio → whole submit 422.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'counted_units' => '3.000'],
            ['ingredient_uuid' => $flour->uuid, 'counted_pieces' => 2],
        ],
    ])->assertUnprocessable();

    expect(StockCount::query()->count())->toBe(0);
    expect(StockMovement::query()->count())->toBe(0);
    // Balance untouched.
    expect((string) BranchStock::query()->where('ingredient_id', $milk->id)->value('quantity'))->toBe('5.000');
});

// =================== PIECE RULES ===================

it('counts a base-unit=piece ingredient in pieces with implicit ratio 1', function (): void {
    $ctx = makeMerchantActor();
    $eggs = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Eggs',
        'unit' => 'piece',
        'allow_fractional_pieces' => false,
    ]);
    seedBalance($ctx, $eggs, '30.000');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [
            ['ingredient_uuid' => $eggs->uuid, 'counted_pieces' => 28],
        ],
    ])->assertCreated();

    $line = StockCountLine::query()->firstOrFail();
    expect((string) $line->counted_units)->toBe('28.000');
    expect((string) $line->variance_units)->toBe('-2.000');
});

it('rejects fractional counted pieces when allow_fractional_pieces is false', function (): void {
    $ctx = makeMerchantActor();
    $eggs = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Eggs',
        'unit' => 'piece',
        'allow_fractional_pieces' => false,
    ]);
    seedBalance($ctx, $eggs, '30.000');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [
            ['ingredient_uuid' => $eggs->uuid, 'counted_pieces' => 4.7],
        ],
    ])->assertUnprocessable();
});

// =================== LIST + TENANCY + GATES ===================

it('lists counts for a branch with lines, newest first', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Milk', 'unit' => 'l',
    ]);
    seedBalance($ctx, $milk, '5.000');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $milk->uuid, 'counted_units' => '5.000']],
    ])->assertCreated();

    $response = $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock-counts")->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.lines.0.ingredient.name'))->toBe('Milk');
});

it('refuses a cross-tenant branch (404) and a foreign ingredient (422)', function (): void {
    $ctx = makeMerchantActor();
    $other = makeMerchantActor();
    app(\App\Support\MerchantTenantContext::class)->set($ctx['company']->id);
    $this->actingAs($ctx['user']);

    $this->postJson("/api/branches/{$other['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => (string) \Illuminate\Support\Str::uuid(), 'counted_units' => 1]],
    ])->assertNotFound();

    $foreign = Ingredient::factory()->for($other['company'], 'company')->create(['name' => 'Foreign']);
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $foreign->uuid, 'counted_units' => 1]],
    ])->assertUnprocessable();
});

it('lets a Viewer list but not submit counts', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);

    $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock-counts")->assertOk();
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $milk->uuid, 'counted_units' => 1]],
    ])->assertForbidden();
});
