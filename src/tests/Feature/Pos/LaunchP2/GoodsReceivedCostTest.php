<?php

declare(strict_types=1);

/*
 * LAUNCH-P2 P2-2 (M9) + P2-3 — goods received is the one way stock comes in,
 * and it costs the stock correctly:
 *  - the movement carries the price actually paid per BASE unit (6dp);
 *  - the ingredient's cost becomes the company-wide weighted average
 *    (on hand ≤ 0 → the price paid), audited with old/new cost + receipt;
 *  - a back-dated receipt dates its movements at the receipt date;
 *  - delivery stays a separate expense; a no-price line keeps the average;
 *  - each line may be entered in a purchase unit with a price per that unit.
 */

use App\Enums\StockMovementType;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\IngredientStock;
use App\Models\PurchaseReceiptLine;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p2Flour(array $ctx, array $overrides = []): Ingredient
{
    return Ingredient::factory()->for($ctx['company'], 'company')->create(array_merge([
        'name' => 'Flour',
        'unit' => 'g',
        'default_unit_cost' => '0.000300',
        'min_stock_threshold' => null,
    ], $overrides));
}

function p2OnHand(array $ctx, Ingredient $ingredient, string $qty): void
{
    BranchStock::factory()->for($ctx['branch'], 'branch')->for($ingredient, 'ingredient')->create(['quantity' => $qty]);
}

it('stamps the paid price per base unit and averages the cost over company-wide stock', function (): void {
    $ctx = makeMerchantActor();
    $flour = p2Flour($ctx);
    p2OnHand($ctx, $flour, '6000');
    IngredientStock::query()->create(['company_id' => $ctx['company']->id, 'ingredient_id' => $flour->id, 'quantity' => '4000']);

    // 25 kg at 0.350 OMR per kg of a gram-based ingredient.
    $receipt = $this->postJson('/api/purchase-receipts', [
        'lines' => [[
            'item_type' => 'ingredient', 'item_uuid' => $flour->uuid,
            'unit' => 'kg', 'quantity' => '25', 'unit_price' => '0.350',
        ]],
    ])->assertCreated()->json('data');

    $line = PurchaseReceiptLine::query()->sole();
    expect((string) $line->quantity)->toBe('25000.000')
        ->and((string) $line->line_cost)->toBe('8.750')
        ->and($line->purchase_unit)->toBe('kg')
        ->and((string) $line->purchase_quantity)->toBe('25.000')
        ->and((string) $line->unit_price)->toBe('0.350')
        ->and((string) $line->unit_cost)->toBe('0.00035');

    $received = StockMovement::query()->where('movement_type', StockMovementType::Received->value)->sole();
    expect((string) $received->unit_cost_at_time)->toBe('0.00035')
        ->and((string) $received->quantity)->toBe('25000.000')
        ->and($received->reference_type)->toBe(PurchaseReceiptLine::class)
        ->and((int) $received->reference_id)->toBe($line->id);

    // (10000 × 0.0003 + 25000 × 0.00035) / 35000 = 0.000335714… → 0.000336
    expect((string) $flour->fresh()->default_unit_cost)->toBe('0.000336');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.ingredient.cost_averaged',
        'auditable_id' => $flour->id,
    ]);
    $audit = DB::table('pos_audit_logs')->where('event', 'inventory.ingredient.cost_averaged')->sole();
    expect(json_decode((string) $audit->old_values, true))->toBe(['default_unit_cost' => '0.0003'])
        ->and(json_decode((string) $audit->new_values, true))->toMatchArray([
            'default_unit_cost' => '0.000336',
            'on_hand_before' => '10000.000',
            'paid_unit_cost' => '0.00035',
            'purchase_receipt_uuid' => $receipt['uuid'],
        ]);
});

it('uses the price paid when the company holds nothing or is oversold', function (): void {
    $ctx = makeMerchantActor();
    $flour = p2Flour($ctx);
    p2OnHand($ctx, $flour, '-50');

    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'quantity' => '1000', 'line_cost' => '0.420']],
    ])->assertCreated();

    expect((string) $flour->fresh()->default_unit_cost)->toBe('0.00042');
    expect((string) StockMovement::query()->where('movement_type', 'received')->sole()->unit_cost_at_time)->toBe('0.00042');
});

it('dates a back-dated receipt movements at the receipt date and moves its split at the new average', function (): void {
    $ctx = makeMerchantActor();
    $flour = p2Flour($ctx, ['default_unit_cost' => '0']);
    $date = now()->subDays(3)->toDateString();

    $this->postJson('/api/purchase-receipts', [
        'received_at' => $date,
        'lines' => [[
            'item_type' => 'ingredient', 'item_uuid' => $flour->uuid,
            'unit' => 'kg', 'quantity' => '10', 'unit_price' => '0.400',
            'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '4']],
        ]],
    ])->assertCreated();

    $movements = StockMovement::query()->orderBy('id')->get();
    expect($movements->pluck('movement_type')->map->value->all())
        ->toBe(['received', 'allocation_out', 'allocation_in']);
    foreach ($movements as $movement) {
        expect($movement->occurred_at->toDateString())->toBe($date);
        expect((string) $movement->unit_cost_at_time)->toBe('0.0004');
    }
    expect((string) $movements[2]->quantity)->toBe('4000.000');

    // A receipt dated today lands now, not at midnight.
    $this->postJson('/api/purchase-receipts', [
        'received_at' => now()->toDateString(),
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'quantity' => '5', 'line_cost' => '0.002']],
    ])->assertCreated();
    $today = StockMovement::query()->where('movement_type', 'received')->orderByDesc('id')->first();
    expect($today->occurred_at->diffInSeconds(now(), true))->toBeLessThan(60);
});

it('keeps delivery a separate expense and leaves the cost alone on a line paid nothing', function (): void {
    $ctx = makeMerchantActor();
    $flour = p2Flour($ctx);
    p2OnHand($ctx, $flour, '1000');

    $this->postJson('/api/purchase-receipts', [
        'lines' => [
            ['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'quantity' => '1000', 'line_cost' => '0.500'],
        ],
        'charges' => [['name' => 'Delivery', 'category' => 'delivery', 'amount' => '5.000']],
    ])->assertCreated();
    // (1000 × 0.0003 + 1000 × 0.0005) / 2000 = 0.0004 — the 5.000 delivery is not in it.
    expect((string) $flour->fresh()->default_unit_cost)->toBe('0.0004');

    // A free line (no price paid): spend 0, average unchanged.
    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'quantity' => '2000', 'line_cost' => '0']],
    ])->assertCreated();
    expect((string) $flour->fresh()->default_unit_cost)->toBe('0.0004');
    $free = StockMovement::query()->where('movement_type', 'received')->orderByDesc('id')->first();
    expect((string) $free->unit_cost_at_time)->toBe('0.000');
});

it('converts every unit the ingredient knows: its metric pair, an extra unit and its piece unit', function (): void {
    $ctx = makeMerchantActor();
    // A kg ingredient bought by the gram.
    $saffron = p2Flour($ctx, ['name' => 'Saffron', 'unit' => 'kg', 'default_unit_cost' => '0']);
    // A ml ingredient bought by the box of 12 litre bottles, or by the bottle.
    $milk = p2Flour($ctx, [
        'name' => 'Milk', 'unit' => 'ml', 'default_unit_cost' => '0',
        'piece_unit_label' => 'bottle', 'units_per_piece' => '1000.0000',
    ]);
    IngredientAltUnit::query()->create([
        'uuid' => (string) Str::uuid(), 'ingredient_id' => $milk->id, 'company_id' => $ctx['company']->id,
        'name' => 'box', 'factor' => '12000.0000',
    ]);

    $this->postJson('/api/purchase-receipts', [
        'lines' => [
            ['item_type' => 'ingredient', 'item_uuid' => $saffron->uuid, 'unit' => 'g', 'quantity' => '0.3', 'unit_price' => '1.200'],
            ['item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'unit' => 'box', 'quantity' => '2', 'unit_price' => '6.000'],
            ['item_type' => 'ingredient', 'item_uuid' => $milk->uuid, 'unit' => '@piece', 'quantity' => '3', 'unit_price' => '0.520'],
        ],
    ])->assertCreated();

    $lines = PurchaseReceiptLine::query()->orderBy('display_order')->get();
    // 0.3 g of a kg ingredient survives (4 decimals); 1.200 OMR/g = 1200 OMR/kg.
    expect((string) $lines[0]->quantity)->toBe('0.0003')
        ->and((string) $lines[0]->unit_cost)->toBe('1200.000')
        ->and((string) $lines[0]->line_cost)->toBe('0.360');
    // 2 boxes × 12000 ml at 6.000 per box = 0.0005 per ml.
    expect((string) $lines[1]->quantity)->toBe('24000.000')
        ->and((string) $lines[1]->unit_cost)->toBe('0.0005')
        ->and((string) $lines[1]->line_cost)->toBe('12.000');
    // 3 bottles × 1000 ml at 0.520 per bottle = 0.00052 per ml.
    expect((string) $lines[2]->quantity)->toBe('3000.000')
        ->and($lines[2]->purchase_unit)->toBe('@piece')
        ->and((string) $lines[2]->unit_cost)->toBe('0.00052');

    // Average after both milk lines: (24000 × 0.0005 + 3000 × 0.00052) / 27000.
    expect((string) $milk->fresh()->default_unit_cost)->toBe('0.000502');
    expect((float) IngredientStock::query()->where('ingredient_id', $saffron->id)->value('quantity'))->toBe(0.0003);

    // The saved document reads back the way it was entered.
    $uuid = DB::table('pos_purchase_receipts')->value('uuid');
    $shown = $this->getJson("/api/purchase-receipts/{$uuid}")->assertOk()->json('data.lines');
    expect($shown[1])->toMatchArray([
        'purchase_unit' => 'box', 'purchase_quantity' => '2.000', 'unit_price' => '6.000',
        'unit_cost' => '0.0005', 'quantity' => '24000.000',
    ]);
});

it('refuses a unit the ingredient does not know', function (): void {
    $ctx = makeMerchantActor();
    $flour = p2Flour($ctx);

    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'unit' => 'crate', 'quantity' => '1', 'unit_price' => '1']],
    ])->assertStatus(422)->assertJsonPath('message', "Unit 'crate' is not defined for this ingredient.");
    expect(StockMovement::query()->count())->toBe(0);
});
