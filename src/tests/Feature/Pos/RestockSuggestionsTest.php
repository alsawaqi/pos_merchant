<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase 5c — restock smart-suggestions coverage (blueprint §5.6.4).
 *
 * GET /api/branches/{branch:uuid}/restock-suggestions proposes a per-ingredient
 * order quantity from current branch stock + min_stock_threshold + the trailing
 * consumption rate. Read-only, branch-scoped, InventoryView-gated.
 */
function seedStock(int $branchId, int $ingredientId, string $qty): void
{
    DB::table('pos_branch_stock')->insert([
        'branch_id' => $branchId, 'ingredient_id' => $ingredientId,
        'quantity' => $qty, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function seedConsumptionMovement(int $branchId, int $ingredientId, float $qty, int $daysAgo): void
{
    DB::table('pos_stock_movements')->insert([
        'branch_id' => $branchId, 'ingredient_id' => $ingredientId,
        'movement_type' => 'sale_consumption', 'quantity' => -abs($qty),
        'unit_cost_at_time' => 0,
        'occurred_at' => now()->subDays($daysAgo), 'created_at' => now()->subDays($daysAgo),
    ]);
}

it('suggests a restock quantity from the trailing consumption rate', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk', 'min_stock_threshold' => null]);

    seedStock($ctx['branch']->id, $milk->id, '2.000');
    seedConsumptionMovement($ctx['branch']->id, $milk->id, 60, 5); // 60 over the 30-day window → 2.000/day

    $res = $this->getJson("/api/branches/{$ctx['branch']->uuid}/restock-suggestions")->assertOk();

    $row = collect($res->json('data'))->firstWhere('ingredient_id', $milk->id);
    expect($row)->not->toBeNull();
    expect($row['avg_daily_consumption'])->toBe('2.000');
    expect($row['target_level'])->toBe('28.000');       // 2/day × 14 cover days
    expect($row['suggested_quantity'])->toBe('26.000');  // 28 target − 2 on hand
    expect($row['reason'])->toBe('consumption_forecast');
    expect($res->json('meta.window_days'))->toBe(30);
    expect($res->json('meta.cover_days'))->toBe(14);
});

it('honors the cover_days horizon parameter', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['min_stock_threshold' => null]);
    seedStock($ctx['branch']->id, $milk->id, '2.000');
    seedConsumptionMovement($ctx['branch']->id, $milk->id, 60, 5); // 2/day

    $res = $this->getJson("/api/branches/{$ctx['branch']->uuid}/restock-suggestions?cover_days=7")->assertOk();

    $row = collect($res->json('data'))->firstWhere('ingredient_id', $milk->id);
    expect($row['target_level'])->toBe('14.000');        // 2/day × 7
    expect($row['suggested_quantity'])->toBe('12.000');   // 14 − 2
});

it('suggests topping up to the min_stock_threshold even with no consumption', function (): void {
    $ctx = makeMerchantActor();
    $sugar = Ingredient::factory()->for($ctx['company'], 'company')->create(['min_stock_threshold' => '10.000']);
    seedStock($ctx['branch']->id, $sugar->id, '3.000');

    $res = $this->getJson("/api/branches/{$ctx['branch']->uuid}/restock-suggestions")->assertOk();

    $row = collect($res->json('data'))->firstWhere('ingredient_id', $sugar->id);
    expect($row['suggested_quantity'])->toBe('7.000');   // 10 threshold − 3 on hand
    expect($row['reason'])->toBe('below_threshold');
});

it('omits ingredients that are already above target', function (): void {
    $ctx = makeMerchantActor();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create(['min_stock_threshold' => '5.000']);
    seedStock($ctx['branch']->id, $flour->id, '50.000'); // well above threshold, no consumption

    $res = $this->getJson("/api/branches/{$ctx['branch']->uuid}/restock-suggestions")->assertOk();

    expect(collect($res->json('data'))->firstWhere('ingredient_id', $flour->id))->toBeNull();
});

it('scopes stock + consumption to the requested branch', function (): void {
    $ctx = makeMerchantActor();
    $branchA = $ctx['branch'];
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $bean = Ingredient::factory()->for($ctx['company'], 'company')->create(['min_stock_threshold' => '10.000']);

    seedStock($branchA->id, $bean->id, '8.000');     // below threshold at A → suggest 2
    seedStock($branchB->id, $bean->id, '100.000');   // plenty at B (must be ignored)
    seedConsumptionMovement($branchB->id, $bean->id, 300, 5); // B's burn (must be ignored)

    $res = $this->getJson("/api/branches/{$branchA->uuid}/restock-suggestions")->assertOk();

    $row = collect($res->json('data'))->firstWhere('ingredient_id', $bean->id);
    expect($row['current_quantity'])->toBe('8.000');
    expect($row['suggested_quantity'])->toBe('2.000'); // 10 − 8; B's numbers don't leak in
});

it('never suggests another company ingredient', function (): void {
    $ctx = makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Ingredient::factory()->for($other, 'company')->create(['min_stock_threshold' => '99.000']);

    $res = $this->getJson("/api/branches/{$ctx['branch']->uuid}/restock-suggestions")->assertOk();

    expect(collect($res->json('data'))->firstWhere('ingredient_id', $foreign->id))->toBeNull();
});

it('is available to a read-only inventory role (view-gated, not manage-gated)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value); // has inventory.view, no restock authority

    $this->getJson("/api/branches/{$ctx['branch']->uuid}/restock-suggestions")->assertOk();
});
