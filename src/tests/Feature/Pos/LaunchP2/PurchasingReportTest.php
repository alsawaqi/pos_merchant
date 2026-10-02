<?php

declare(strict_types=1);

/*
 * LAUNCH-P2 P2-5 — the purchasing report counts each purchase ONCE (a
 * restock request fulfilled from the warehouse moves goods already bought;
 * it is not a purchase) and credits the spend to the supplier ON the
 * receipt, not the ingredient's main supplier.
 */

use App\Enums\StockMovementType;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function p2Report(): array
{
    $today = now()->toDateString();

    return test()->getJson("/api/reports/restock-purchasing?date_from={$today}&date_to={$today}")->assertOk()->json('data');
}

it('counts a purchase once when a restock request is fulfilled from the warehouse', function (): void {
    $ctx = makeMerchantActor();
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'g', 'default_unit_cost' => '0']);

    // Bought once: 10 kg for 4.000.
    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'unit' => 'kg', 'quantity' => '10', 'unit_price' => '0.400']],
    ])->assertCreated();

    // A branch asked for 3 kg; HQ sends it from the warehouse.
    $request = RestockRequest::factory()->approved()->create(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id]);
    RestockRequestLine::factory()->create([
        'restock_request_id' => $request->id, 'ingredient_id' => $flour->id,
        'quantity_requested' => '3000', 'unit_at_set' => 'g',
    ]);
    $this->postJson("/api/restock-requests/{$request->uuid}/allocate", [])->assertOk();
    expect(StockMovement::query()->where('movement_type', StockMovementType::Restock->value)->count())->toBe(1);

    $report = p2Report();
    expect($report['headline']['total_cost'])->toBe('4.000')
        ->and($report['headline']['total_qty'])->toBe('10000.000')
        ->and($report['headline']['event_count'])->toBe(1);
    expect($report['top_purchased'][0]['cost'])->toBe('4.000');
});

it('credits the spend to the supplier on the receipt, not the ingredient main supplier', function (): void {
    $ctx = makeMerchantActor();
    $main = Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'Main Supplier']);
    $other = Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'Market Stall']);
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create([
        'unit' => 'g', 'default_unit_cost' => '0', 'primary_supplier_id' => $main->id,
    ]);

    $this->postJson('/api/purchase-receipts', [
        'supplier_uuid' => $other->uuid,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'quantity' => '5000', 'line_cost' => '2.500']],
    ])->assertCreated();
    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $flour->uuid, 'quantity' => '1000', 'line_cost' => '0.700']],
    ])->assertCreated();

    $bySupplier = collect(p2Report()['by_supplier'])->keyBy('supplier_name');
    expect($bySupplier->keys()->sort()->values()->all())->toBe(['Market Stall', 'Unassigned'])
        ->and($bySupplier['Market Stall']['cost'])->toBe('2.500')
        ->and($bySupplier['Market Stall']['supplier_id'])->toBe($other->id)
        ->and($bySupplier['Unassigned']['cost'])->toBe('0.700');
});

it('finds the receipt supplier of a receipt booked before the line link existed', function (): void {
    $ctx = makeMerchantActor();
    $supplier = Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'Old Supplier']);
    $flour = Ingredient::factory()->for($ctx['company'], 'company')->create(['unit' => 'g']);

    // Launch-p1 shape: the received movement points at the line's expense.
    $expense = Expense::factory()->create(['company_id' => $ctx['company']->id, 'amount' => '3.000']);
    $receipt = PurchaseReceipt::query()->create([
        'company_id' => $ctx['company']->id, 'supplier_id' => $supplier->id, 'status' => 'received',
        'items_total' => '3.000', 'charges_total' => '0.000', 'grand_total' => '3.000', 'received_at' => now(),
    ]);
    PurchaseReceiptLine::query()->create([
        'purchase_receipt_id' => $receipt->id, 'item_type' => 'ingredient', 'ingredient_id' => $flour->id,
        'item_name' => 'Flour', 'quantity' => '6000', 'unit' => 'g', 'line_cost' => '3.000', 'expense_id' => $expense->id,
    ]);
    StockMovement::factory()->for($flour, 'ingredient')->create([
        'branch_id' => null, 'movement_type' => StockMovementType::Received->value,
        'quantity' => '6000', 'unit_cost_at_time' => '0.0005',
        'reference_type' => Expense::class, 'reference_id' => $expense->id,
    ]);

    $bySupplier = p2Report()['by_supplier'];
    expect($bySupplier)->toHaveCount(1)
        ->and($bySupplier[0]['supplier_name'])->toBe('Old Supplier')
        ->and($bySupplier[0]['cost'])->toBe('3.000');
});
