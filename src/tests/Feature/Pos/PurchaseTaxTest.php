<?php

declare(strict_types=1);

/**
 * PT — purchase/input tax tracking across the buy side.
 *
 * Covers: tax on a Goods Received Note line (gross expense + receipt tax_total);
 * tax on a stock receive; tax on a manual expense (amount stays net, the booked
 * expense is the gross); the Sales report's total purchase-tax figure under both
 * the informational + recoverable treatments; and the recoverable setting.
 */

use App\Enums\ExpenseStatus;
use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\PurchaseReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ptIngredient(array $ctx, string $name = 'Tomato'): Ingredient
{
    return Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => $name, 'unit' => 'kg']);
}

it('books the gross expense + records the tax on a Goods Received Note line', function (): void {
    $ctx = makeMerchantActor();
    $tomato = ptIngredient($ctx);

    $res = $this->postJson('/api/purchase-receipts', [
        'lines' => [[
            'item_type' => 'ingredient', 'item_uuid' => $tomato->uuid,
            'quantity' => '100', 'line_cost' => '30.000',
            'tax_amount' => '1.500', 'tax_rate' => '5',
        ]],
    ])->assertCreated();

    // Receipt: items 30 (net), tax 1.5, grand 31.5 (gross).
    expect($res->json('data.items_total'))->toBe('30.000');
    expect($res->json('data.tax_total'))->toBe('1.500');
    expect($res->json('data.grand_total'))->toBe('31.500');
    expect($res->json('data.lines.0.tax_amount'))->toBe('1.500');
    expect($res->json('data.lines.0.tax_rate'))->toBe('5.00');

    // The booked expense is the GROSS, with the tax portion recorded.
    $expense = Expense::query()->where('company_id', $ctx['company']->id)->where('category', 'ingredients')->firstOrFail();
    expect((string) $expense->amount)->toBe('31.500');
    expect((string) $expense->tax_amount)->toBe('1.500');
    expect((string) $expense->tax_rate)->toBe('5.00');
});

it('records tax on a stock receive', function (): void {
    $ctx = makeMerchantActor();
    $tomato = ptIngredient($ctx);

    $this->postJson("/api/ingredients/{$tomato->uuid}/stock/receive", [
        'quantity' => '50', 'total_cost' => '20', 'tax_amount' => '1', 'tax_rate' => '5',
    ])->assertOk();

    $expense = Expense::query()->where('category', 'ingredients')->firstOrFail();
    expect((float) $expense->amount)->toBe(21.0); // 20 net + 1 tax
    expect((float) $expense->tax_amount)->toBe(1.0);
});

it('keeps a manual expense amount net and books the gross with tax', function (): void {
    makeMerchantActor();

    $this->postJson('/api/expenses', [
        'category' => 'other', 'amount' => '50', 'tax_amount' => '2.5', 'tax_rate' => '5',
    ])->assertCreated();

    $expense = Expense::query()->where('category', 'other')->firstOrFail();
    expect((float) $expense->amount)->toBe(52.5); // 50 net + 2.5 tax = gross
    expect((float) $expense->tax_amount)->toBe(2.5);
});

it('a no-tax purchase behaves exactly as before (amount = cost, tax 0)', function (): void {
    $ctx = makeMerchantActor();
    $tomato = ptIngredient($ctx);

    $this->postJson("/api/ingredients/{$tomato->uuid}/stock/receive", [
        'quantity' => '10', 'total_cost' => '8',
    ])->assertOk();

    $expense = Expense::query()->where('category', 'ingredients')->firstOrFail();
    expect((float) $expense->amount)->toBe(8.0);
    expect((float) $expense->tax_amount)->toBe(0.0);
    expect($expense->tax_rate)->toBeNull();
});

it('totals the purchase tax on the Sales report + honours the recoverable setting', function (): void {
    $ctx = makeMerchantActor();
    $companyId = $ctx['company']->id;

    // Two purchase expenses (gross), 3 tax total, in the window.
    Expense::query()->create([
        'company_id' => $companyId, 'branch_id' => $ctx['branch']->id, 'category' => 'ingredients',
        'amount' => '21.000', 'tax_amount' => '1.000', 'tax_rate' => '5',
        'logged_at' => '2026-06-15 10:00:00', 'status' => ExpenseStatus::Recorded->value,
    ]);
    Expense::query()->create([
        'company_id' => $companyId, 'branch_id' => $ctx['branch']->id, 'category' => 'stock_purchases',
        'amount' => '42.000', 'tax_amount' => '2.000', 'tax_rate' => '5',
        'logged_at' => '2026-06-15 11:00:00', 'status' => ExpenseStatus::Recorded->value,
    ]);

    // Informational (default): operating_expenses = 63, purchase_tax_paid = 3,
    // net_profit = 0 sales − 63 = −63 (tax NOT credited).
    $info = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($info->json('data.headline.operating_expenses'))->toBe('63.000');
    expect($info->json('data.headline.purchase_tax_paid'))->toBe('3.000');
    expect($info->json('data.headline.purchase_tax_recoverable'))->toBeFalse();
    expect($info->json('data.headline.net_profit'))->toBe('-63.000');

    // Recoverable ON: the 3 tax is credited back → net_profit = −63 + 3 = −60.
    CompanySetting::query()->create([
        'company_id' => $companyId,
        'key' => CompanySetting::KEY_PURCHASE_TAX_RECOVERABLE,
        'value' => true,
    ]);
    $rec = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($rec->json('data.headline.operating_expenses'))->toBe('63.000'); // gross unchanged
    expect($rec->json('data.headline.purchase_tax_paid'))->toBe('3.000');
    expect($rec->json('data.headline.purchase_tax_recoverable'))->toBeTrue();
    expect($rec->json('data.headline.net_profit'))->toBe('-60.000');
});

it('reads + writes the purchase-tax-recoverable setting', function (): void {
    makeMerchantActor();

    expect($this->getJson('/api/settings/purchase-tax-recoverable')->assertOk()->json('data.purchase_tax_recoverable'))->toBeFalse();

    $this->putJson('/api/settings/purchase-tax-recoverable', ['purchase_tax_recoverable' => true])
        ->assertOk()
        ->assertJsonPath('data.purchase_tax_recoverable', true);

    expect($this->getJson('/api/settings/purchase-tax-recoverable')->json('data.purchase_tax_recoverable'))->toBeTrue();
});

it('refuses tax on a no_cost receive', function (): void {
    $ctx = makeMerchantActor();
    $tomato = ptIngredient($ctx);

    $this->postJson("/api/ingredients/{$tomato->uuid}/stock/receive", [
        'quantity' => '10', 'no_cost' => true, 'tax_amount' => '1',
    ])->assertStatus(422);

    expect(Expense::query()->count())->toBe(0);
});
