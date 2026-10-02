<?php

declare(strict_types=1);

/**
 * LAUNCH-P3 P3-4 — prep items have no stock.
 *
 * Excluded from goods received, stock counts, transfers, restock requests,
 * the central warehouse, the stock and low-stock pages and the dashboard
 * low-stock card; no movement can ever be written for one. Waste of a prep
 * item records the waste of its exploded raw ingredients at the current
 * cost, as one event naming the prep item.
 */

use App\Actions\Pos\Inventory\WriteStockMovementAction;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\PurchaseReceipt;
use App\Models\RestockRequest;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** @return array{tomato: Ingredient, oil: Ingredient, sauce: Ingredient} */
function p3SauceFixture(array $ctx): array
{
    $tomato = p3Ingredient($ctx['company'], 'Tomato', 'g', '0.002');
    $oil = p3Ingredient($ctx['company'], 'Oil', 'ml', '0.004');
    $sauce = p3Prep($ctx['company'], 'Tomato sauce', 'ml', '2000', [[$tomato, '1500'], [$oil, '100']]);

    return ['tomato' => $tomato, 'oil' => $oil, 'sauce' => $sauce];
}

it('refuses goods received, a count, a transfer and a restock request for a prep item', function (): void {
    $ctx = makeMerchantActor();
    $fx = p3SauceFixture($ctx);
    $other = Branch::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'ingredient', 'item_uuid' => $fx['sauce']->uuid, 'quantity' => '1000', 'unit_price' => '1.000',
    ]]])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'is a prep item: it has no stock of its own'));

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/stock-counts", [
        'lines' => [['ingredient_uuid' => $fx['sauce']->uuid, 'counted_units' => '500']],
    ])->assertStatus(422);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/transfers", [
        'to_branch_uuid' => $other->uuid,
        'lines' => [['ingredient_uuid' => $fx['sauce']->uuid, 'quantity' => '100']],
    ])->assertStatus(422);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [['ingredient_uuid' => $fx['sauce']->uuid, 'quantity_requested' => '1000']],
    ])->assertStatus(422);

    expect(PurchaseReceipt::query()->count())->toBe(0)
        ->and(StockCount::query()->count())->toBe(0)
        ->and(RestockRequest::query()->count())->toBe(0)
        ->and(StockMovement::query()->where('ingredient_id', $fx['sauce']->id)->count())->toBe(0)
        ->and(BranchStock::query()->where('ingredient_id', $fx['sauce']->id)->count())->toBe(0);
});

it('has no central warehouse for a prep item', function (): void {
    $ctx = makeMerchantActor();
    $fx = p3SauceFixture($ctx);

    $this->getJson("/api/ingredients/{$fx['sauce']->uuid}/stock")->assertNotFound();
    $this->postJson("/api/ingredients/{$fx['sauce']->uuid}/stock/receive", ['no_cost' => true, 'quantity' => '100'])->assertNotFound();
    $this->postJson("/api/ingredients/{$fx['sauce']->uuid}/stock/adjust", ['signed_quantity' => '5', 'note' => 'x'])->assertNotFound();
});

it('never writes a stock movement for a prep item, whatever the path', function (): void {
    $ctx = makeMerchantActor();
    $fx = p3SauceFixture($ctx);

    expect(fn () => app(WriteStockMovementAction::class)->handle(
        branch: $ctx['branch'],
        ingredient: $fx['sauce'],
        type: StockMovementType::Adjustment,
        quantity: '10',
    ))->toThrow(RuntimeException::class, 'is a prep item: it has no stock of its own');

    expect(fn () => app(WriteStockMovementAction::class)->handle(
        branch: null,
        ingredient: $fx['sauce'],
        type: StockMovementType::Received,
        quantity: '10',
    ))->toThrow(RuntimeException::class);
    expect(StockMovement::query()->count())->toBe(0);
});

it('keeps prep items off the stock page, its low-stock filter, the dashboard card and restock suggestions', function (): void {
    $ctx = makeMerchantActor();
    $fx = p3SauceFixture($ctx);
    // Even a (bad) minimum on a prep item must never flag it.
    DB::table('pos_ingredients')->where('id', $fx['sauce']->id)->update(['min_stock_threshold' => '100']);

    $stock = $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock")->assertOk();
    expect(collect($stock->json('data'))->pluck('ingredient_id')->all())->not->toContain($fx['sauce']->id)
        ->and(collect($stock->json('data'))->pluck('ingredient_id')->all())->toContain($fx['tomato']->id)
        ->and($stock->json('meta.below_minimum_count'))->toBe(0);

    $low = $this->getJson("/api/branches/{$ctx['branch']->uuid}/stock?filter=low")->assertOk();
    expect(collect($low->json('data'))->pluck('ingredient_id')->all())->not->toContain($fx['sauce']->id);

    $card = $this->getJson('/api/dashboard/summary')->assertOk()->json('data.low_stock');
    expect($card['below_minimum'])->toBe(0)
        ->and($card['negative'])->toBe(0);

    $suggestions = $this->getJson("/api/branches/{$ctx['branch']->uuid}/restock-suggestions")->assertOk()->json('data');
    expect(collect($suggestions)->pluck('ingredient_uuid')->filter()->all())->not->toContain($fx['sauce']->uuid);
});

it('wastes a prep item as the waste of its raw ingredients at current cost, one event naming it', function (): void {
    $ctx = makeMerchantActor();
    $fx = p3SauceFixture($ctx);
    p3Stock($ctx['branch'], $fx['tomato'], '5000');
    p3Stock($ctx['branch'], $fx['oil'], '1000');

    // 1 L of sauce: tomato 1000 × 1500 ÷ 2000 = 750 g, oil 50 ml.
    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $fx['sauce']->uuid,
        'quantity' => '1',
        'unit' => 'l',
        'reason' => 'spoiled',
        'notes' => 'left out overnight',
    ])->assertCreated();

    // 750 × 0.002 + 50 × 0.004 = 1.500 + 0.200.
    expect($response->json('total_cost'))->toBe('1.700')
        ->and($response->json('prep_item.name'))->toBe('Tomato sauce')
        ->and($response->json('records'))->toHaveCount(2);

    $records = WasteRecord::query()->orderBy('id')->get();
    expect($records->pluck('ingredient_id')->all())->toBe([$fx['tomato']->id, $fx['oil']->id])
        ->and($records->map(fn ($r) => (string) $r->quantity)->all())->toBe(['750.000', '50.000'])
        ->and($records->map(fn ($r) => (string) $r->unit_cost_at_time)->all())->toBe(['0.002', '0.004'])
        ->and($records->pluck('notes')->unique()->all())->toBe(['Prep item: Tomato sauce, 1 l — left out overnight']);

    // Raw stock goes down; the prep item never gets a row.
    expect((string) BranchStock::query()->where('ingredient_id', $fx['tomato']->id)->value('quantity'))->toBe('4250.000')
        ->and((string) BranchStock::query()->where('ingredient_id', $fx['oil']->id)->value('quantity'))->toBe('950.000')
        ->and(BranchStock::query()->where('ingredient_id', $fx['sauce']->id)->exists())->toBeFalse();

    $event = DB::table('pos_audit_logs')->where('event', 'inventory.waste.prep_recorded')->sole();
    $values = json_decode($event->new_values, true);
    expect((int) $event->auditable_id)->toBe($fx['sauce']->id)
        ->and($values['prep_item_name'])->toBe('Tomato sauce')
        ->and($values['amount'])->toBe('1 l')
        ->and($values['total_cost'])->toBe('1.700')
        ->and(array_column($values['lines'], 'ingredient_name'))->toBe(['Tomato', 'Oil']);
});

it('explodes nested prep items when wasting', function (): void {
    $ctx = makeMerchantActor();
    $fx = p3SauceFixture($ctx);
    $flour = p3Ingredient($ctx['company'], 'Flour', 'g', '0.0005');
    $base = p3Prep($ctx['company'], 'Pizza base', 'piece', '10', [[$flour, '1000'], [$fx['sauce'], '500']]);
    foreach ([[$flour, '5000'], [$fx['tomato'], '5000'], [$fx['oil'], '1000']] as [$ingredient, $qty]) {
        p3Stock($ctx['branch'], $ingredient, $qty);
    }

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $base->uuid, 'quantity' => '2', 'reason' => 'dropped',
    ])->assertCreated();

    // 2 bases: flour 200; sauce 100 ml → tomato 75, oil 5.
    expect(WasteRecord::query()->orderBy('id')->get()->mapWithKeys(fn ($r) => [$r->ingredient_id => (string) $r->quantity])->all())
        ->toBe([$flour->id => '200.000', $fx['tomato']->id => '75.000', $fx['oil']->id => '5.000']);
});

it('refuses a prep waste the raw stock cannot absorb, naming the ingredient, and writes nothing', function (): void {
    $ctx = makeMerchantActor();
    $fx = p3SauceFixture($ctx);
    p3Stock($ctx['branch'], $fx['tomato'], '5000');
    p3Stock($ctx['branch'], $fx['oil'], '10');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $fx['sauce']->uuid, 'quantity' => '1000', 'reason' => 'spoiled',
    ])->assertStatus(422)->assertJsonPath('message', 'Not enough Oil to waste 1000 ml of Tomato sauce: the branch holds 10.000 ml but the prep item needs 50.000 ml.');

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $fx['sauce']->uuid, 'quantity' => '10', 'reason' => 'reconciliation_variance',
    ])->assertStatus(422);

    expect(WasteRecord::query()->count())->toBe(0)
        ->and((string) BranchStock::query()->where('ingredient_id', $fx['tomato']->id)->value('quantity'))->toBe('5000.000');
});
