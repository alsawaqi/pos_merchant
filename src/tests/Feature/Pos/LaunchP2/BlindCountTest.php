<?php

declare(strict_types=1);

/*
 * LAUNCH-P2 P2-6 — blind counts and a fair variance (portal side).
 *  - variance = counted − the book balance AT THE COUNT MOMENT (movements
 *    dated after it, e.g. from a till whose clock is ahead, stay after it);
 *  - a movement dated before a count that reaches the books after it (a
 *    back-dated receipt or waste) is folded into that count;
 *  - the book side of a count is shown only to users who may see stock.
 */

use App\Actions\Pos\Inventory\WriteStockMovementAction;
use App\Enums\MerchantPermission;
use App\Enums\StockMovementType;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function p2CountIngredient(array $ctx): Ingredient
{
    return Ingredient::factory()->for($ctx['company'], 'company')->create([
        'name' => 'Rice', 'unit' => 'g', 'default_unit_cost' => '0.0005', 'min_stock_threshold' => null,
    ]);
}

function p2StockTo(array $ctx, Ingredient $ingredient, string $grams): void
{
    test()->postJson('/api/purchase-receipts', [
        'destination_branch_uuid' => $ctx['branch']->uuid,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $ingredient->uuid, 'quantity' => $grams, 'line_cost' => '0.500']],
    ])->assertCreated();
}

function p2Balance(array $ctx, Ingredient $ingredient): float
{
    return (float) BranchStock::query()->where('branch_id', $ctx['branch']->id)->where('ingredient_id', $ingredient->id)->value('quantity');
}

it('compares the count with the balance at the count moment, not with movements dated after it', function (): void {
    $ctx = makeMerchantActor();
    $rice = p2CountIngredient($ctx);
    p2StockTo($ctx, $rice, '1000');
    // A sale stamped AFTER the count moment is already on the books (a till
    // whose clock runs ahead): it must not be part of what the count expects.
    app(WriteStockMovementAction::class)->handle(
        $ctx['branch'], $rice, StockMovementType::SaleConsumption, '-100', '0.0005',
        occurredAt: now()->addHour(),
    );

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $rice->uuid, 'counted_units' => '1000']],
    ])->assertCreated()
        ->assertJsonPath('data.lines.0.expected_units', '1000.000')
        ->assertJsonPath('data.lines.0.variance_units', '0.000');

    // Counted 1000 at the count moment, then the later sale: 900.
    expect(p2Balance($ctx, $rice))->toBe(900.0);
});

it('books the shortfall it saw even when later movements took the balance lower', function (): void {
    $ctx = makeMerchantActor();
    $rice = p2CountIngredient($ctx);
    p2StockTo($ctx, $rice, '10');
    app(WriteStockMovementAction::class)->handle(
        $ctx['branch'], $rice, StockMovementType::SaleConsumption, '-8', '0.0005',
        occurredAt: now()->addHour(),
    );

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $rice->uuid, 'counted_units' => '5']],
    ])->assertCreated()->assertJsonPath('data.lines.0.variance_units', '-5.000');

    expect(p2Balance($ctx, $rice))->toBe(-3.0);
    expect((string) WasteRecord::query()->where('reason', 'reconciliation_variance')->sole()->quantity)->toBe('5.000');
});

it('folds a back-dated receipt that the count already saw into that count', function (): void {
    $ctx = makeMerchantActor();
    $rice = p2CountIngredient($ctx);
    p2StockTo($ctx, $rice, '1000');

    // The shelf holds 300 g more than the books: yesterday's delivery is not entered yet.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $rice->uuid, 'counted_units' => '1300']],
    ])->assertCreated()->assertJsonPath('data.lines.0.variance_units', '300.000');
    expect(p2Balance($ctx, $rice))->toBe(1300.0);

    // Now it is entered, dated yesterday: the count already counted it.
    $this->postJson('/api/purchase-receipts', [
        'destination_branch_uuid' => $ctx['branch']->uuid,
        'received_at' => now()->subDay()->toDateString(),
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $rice->uuid, 'quantity' => '300', 'line_cost' => '0.150']],
    ])->assertCreated();

    expect(p2Balance($ctx, $rice))->toBe(1300.0);
    $line = StockCountLine::query()->sole();
    expect((string) $line->expected_units)->toBe('1300.000')
        ->and((string) $line->variance_units)->toBe('0.000')
        ->and((string) $line->late_movement_units)->toBe('300.000');
    $correction = StockMovement::query()->where('movement_type', 'count_correction')->sole();
    expect((string) $correction->quantity)->toBe('-300.000')
        ->and($correction->reference_type)->toBe('pos_stock_count_lines')
        ->and((int) $correction->reference_id)->toBe($line->id);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'inventory.stock_count.late_movement_folded']);
});

it('shrinks the reconciliation loss when a back-dated waste explains part of the shortfall', function (): void {
    $ctx = makeMerchantActor();
    $rice = p2CountIngredient($ctx);
    p2StockTo($ctx, $rice, '1000');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $rice->uuid, 'counted_units' => '900']],
    ])->assertCreated();
    // 60 g spoiled an hour before the count, recorded afterwards.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $rice->uuid, 'quantity' => '60', 'reason' => 'spoiled',
        'occurred_at' => now()->subHour()->toIso8601String(),
    ])->assertCreated();

    expect(p2Balance($ctx, $rice))->toBe(900.0);
    $line = StockCountLine::query()->sole();
    expect((string) $line->expected_units)->toBe('940.000')
        ->and((string) $line->variance_units)->toBe('-40.000');
    expect((string) WasteRecord::query()->where('reason', 'reconciliation_variance')->sole()->quantity)->toBe('40.000');

    $from = now()->subDay()->toDateString();
    $to = now()->toDateString();
    $loss = $this->getJson("/api/reports/loss-waste?date_from={$from}&date_to={$to}")->assertOk()->json('data');
    expect($loss['headline']['total_qty'])->toBe('100.000');
    $shortfall = collect($loss['shortfall'])->firstWhere('ingredient_id', $rice->id);
    expect($shortfall['total_depletion'])->toBe('100.000');
});

it('shows the book side of a count only to users who may see stock values', function (): void {
    $ctx = makeMerchantActor();
    $rice = p2CountIngredient($ctx);
    p2StockTo($ctx, $rice, '1000');

    // A counter: may submit counts (inventory.manage) but not see stock.
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($ctx['company']->id);
    $counter = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant', 'status' => 'active']);
    $counter->givePermissionTo(MerchantPermission::InventoryManage->value);
    $registrar->forgetCachedPermissions();
    $this->actingAs($counter);

    $line = $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $rice->uuid, 'counted_units' => '990']],
    ])->assertCreated()->json('data.lines.0');
    expect($line)->toHaveKeys(['ingredient_id', 'counted_units'])
        ->not->toHaveKeys(['expected_units', 'variance_units', 'variance_value']);

    // The owner sees the variance after the fact.
    $this->actingAs($ctx['user']);
    $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock-counts")->assertOk()
        ->assertJsonPath('data.0.lines.0.expected_units', '1000.000')
        ->assertJsonPath('data.0.lines.0.variance_units', '-10.000');
});
