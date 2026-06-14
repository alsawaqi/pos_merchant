<?php

declare(strict_types=1);

/**
 * PD6 — the Goods Received Note (Saved Purchase Receipt).
 *
 * Covers: a multi-line receipt mixing an ingredient + a bought-in product + a
 * physical item, each booking its categorized purchase expense; named extra
 * charges each booking their own expense; frozen totals; inline branch split
 * (remainder stays central); the received_at stamped onto the booked expenses;
 * over-distribution rolls the WHOLE receipt back; the unit-mode product guard;
 * and cross-tenant isolation.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function grnIngredient(array $ctx, string $name = 'Tomato'): Ingredient
{
    return Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => $name, 'unit' => 'kg']);
}

function grnProduct(array $ctx, array $attrs = []): Product
{
    return Product::factory()->for($ctx['company'], 'company')->create(array_merge(['stock_mode' => 'unit'], $attrs));
}

function grnBranchQty(int $branchId, int $ingredientId): ?string
{
    $row = \App\Models\BranchStock::query()
        ->where('branch_id', $branchId)
        ->where('ingredient_id', $ingredientId)
        ->first();

    return $row === null ? null : (string) $row->quantity;
}

it('records a multi-line receipt, books categorized expenses, and freezes totals', function (): void {
    $ctx = makeMerchantActor();
    $supplier = Supplier::factory()->for($ctx['company'], 'company')->create(['name' => 'Acme Foods']);
    $tomato = grnIngredient($ctx);
    $cola = grnProduct($ctx, ['name' => 'Cola Can']);                  // bought-in -> stock_purchases
    $cup = grnProduct($ctx, ['name' => 'Paper Cup', 'is_internal' => true]); // physical item -> physical_items

    $res = $this->postJson('/api/purchase-receipts', [
        'supplier_uuid' => $supplier->uuid,
        'reference' => 'INV-1001',
        'note' => 'Tuesday delivery',
        'lines' => [
            ['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '100', 'line_cost' => '30.000'],
            ['item_type' => 'product', 'item_uuid' => $cola->uuid, 'quantity' => '48', 'line_cost' => '12.000'],
            ['item_type' => 'product', 'item_uuid' => $cup->uuid, 'quantity' => '500', 'line_cost' => '5.000'],
        ],
        'charges' => [
            ['name' => 'Delivery', 'category' => 'delivery', 'amount' => '4.000'],
            ['name' => 'Customs', 'category' => 'other', 'amount' => '2.000'],
        ],
    ])->assertCreated();

    // Frozen totals: items 47, charges 6, grand 53.
    expect($res->json('data.items_total'))->toBe('47.000');
    expect($res->json('data.charges_total'))->toBe('6.000');
    expect($res->json('data.grand_total'))->toBe('53.000');
    expect($res->json('data.supplier.name'))->toBe('Acme Foods');
    expect($res->json('data.lines'))->toHaveCount(3);
    expect($res->json('data.charges'))->toHaveCount(2);

    // Stock landed in the central warehouse/pool.
    expect((string) IngredientStock::query()->where('ingredient_id', $tomato->id)->firstOrFail()->quantity)->toBe('100.000');
    expect((string) ProductStock::query()->where('product_id', $cola->id)->firstOrFail()->quantity)->toBe('48.000');
    expect((string) ProductStock::query()->where('product_id', $cup->id)->firstOrFail()->quantity)->toBe('500.000');

    // Each line + charge booked an expense under the right category.
    $byCat = fn (string $c): float => (float) Expense::query()
        ->where('company_id', $ctx['company']->id)
        ->where('category', $c)
        ->sum('amount');
    expect($byCat('ingredients'))->toBe(30.0);
    expect($byCat('stock_purchases'))->toBe(12.0);
    expect($byCat('physical_items'))->toBe(5.0);
    expect($byCat('delivery'))->toBe(4.0);
    expect($byCat('other'))->toBe(2.0);

    // The document persisted with its lines + charges.
    $receipt = PurchaseReceipt::query()->where('uuid', $res->json('data.uuid'))->firstOrFail();
    expect($receipt->lines()->count())->toBe(3);
    expect($receipt->charges()->count())->toBe(2);
});

it('distributes a line across branches and leaves the remainder central', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $tomato = grnIngredient($ctx);

    $this->postJson('/api/purchase-receipts', [
        'lines' => [[
            'item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '100', 'line_cost' => '30',
            'allocations' => [
                ['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '40'],
                ['branch_uuid' => $branchB->uuid, 'quantity' => '25'],
            ],
        ]],
    ])->assertCreated();

    // 100 received, 65 distributed -> 35 stays central.
    expect((string) IngredientStock::query()->where('ingredient_id', $tomato->id)->firstOrFail()->quantity)->toBe('35.000');
    expect(grnBranchQty($ctx['branch']->id, $tomato->id))->toBe('40.000');
    expect(grnBranchQty($branchB->id, $tomato->id))->toBe('25.000');

    // The line snapshotted its allocations.
    $receipt = PurchaseReceipt::query()->latest('id')->firstOrFail();
    expect($receipt->lines()->first()->allocations_json)->toHaveCount(2);
});

it('stamps the booked expenses with the received_at date', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);

    $this->postJson('/api/purchase-receipts', [
        'received_at' => '2026-01-15',
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '8']],
        'charges' => [['name' => 'Delivery', 'category' => 'delivery', 'amount' => '2']],
    ])->assertCreated();

    Expense::query()->get()->each(function (Expense $e): void {
        expect($e->logged_at->toDateString())->toBe('2026-01-15');
    });
});

it('books no expense for a zero-cost line', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);

    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '0']],
    ])->assertCreated();

    expect((string) IngredientStock::query()->where('ingredient_id', $tomato->id)->firstOrFail()->quantity)->toBe('10.000');
    expect(Expense::query()->count())->toBe(0);

    $line = PurchaseReceipt::query()->latest('id')->firstOrFail()->lines()->first();
    expect($line->expense_category)->toBeNull();
    expect($line->expense_id)->toBeNull();
});

it('rejects over-distribution and rolls the whole receipt back', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);

    $this->postJson('/api/purchase-receipts', [
        'lines' => [[
            'item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '5',
            'allocations' => [['branch_uuid' => $ctx['branch']->uuid, 'quantity' => '25']],
        ]],
    ])->assertStatus(422);

    // Nothing persisted — no receipt, no stock, no expense.
    expect(PurchaseReceipt::query()->count())->toBe(0);
    expect(IngredientStock::query()->where('ingredient_id', $tomato->id)->exists())->toBeFalse();
    expect(Expense::query()->count())->toBe(0);
});

it('rejects a product that does not hold unit stock', function (): void {
    $ctx = makeMerchantActor();
    $recipeProduct = grnProduct($ctx, ['stock_mode' => 'ingredient']);

    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'product', 'item_uuid' => $recipeProduct->uuid, 'quantity' => '5', 'line_cost' => '3']],
    ])->assertStatus(422);

    expect(PurchaseReceipt::query()->count())->toBe(0);
});

it('requires at least one line', function (): void {
    makeMerchantActor();

    $this->postJson('/api/purchase-receipts', ['lines' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines');
});

it('lists and shows only the tenant own receipts', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);
    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '5']],
    ])->assertCreated();

    // A foreign company's receipt is invisible.
    $other = Company::factory()->create();
    $foreign = PurchaseReceipt::query()->create([
        'company_id' => $other->id, 'items_total' => '1.000', 'charges_total' => '0.000',
        'grand_total' => '1.000', 'status' => 'received', 'received_at' => now(),
    ]);

    $list = $this->getJson('/api/purchase-receipts')->assertOk();
    expect($list->json('data'))->toHaveCount(1);

    $this->getJson("/api/purchase-receipts/{$foreign->uuid}")->assertStatus(404);
});

it('rejects a line referencing another company ingredient or product', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();

    $foreignIngredient = Ingredient::factory()->for($other, 'company')->create(['unit' => 'kg']);
    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $foreignIngredient->uuid, 'quantity' => '5', 'line_cost' => '3']],
    ])->assertStatus(422);

    $foreignProduct = Product::factory()->for($other, 'company')->create(['stock_mode' => 'unit']);
    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'product', 'item_uuid' => $foreignProduct->uuid, 'quantity' => '5', 'line_cost' => '3']],
    ])->assertStatus(422);

    expect(PurchaseReceipt::query()->count())->toBe(0);
});

it('rejects an allocation to another company branch', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);
    $foreignBranch = Branch::factory()->for(Company::factory()->create(), 'company')->create();

    $this->postJson('/api/purchase-receipts', [
        'lines' => [[
            'item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '5',
            'allocations' => [['branch_uuid' => $foreignBranch->uuid, 'quantity' => '4']],
        ]],
    ])->assertStatus(422);

    expect(PurchaseReceipt::query()->count())->toBe(0);
});

it('rejects another company supplier', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);
    $foreignSupplier = Supplier::factory()->for(Company::factory()->create(), 'company')->create();

    $this->postJson('/api/purchase-receipts', [
        'supplier_uuid' => $foreignSupplier->uuid,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '5']],
    ])->assertStatus(422);

    expect(PurchaseReceipt::query()->count())->toBe(0);
});

it('forbids a branch-scoped user from recording a receipt (HQ act)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $tomato = grnIngredient($ctx);

    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '5']],
    ])->assertStatus(403);

    expect(PurchaseReceipt::query()->count())->toBe(0);
});

it('refuses cooked + made-to-order + untracked products — only ready/bought-in (unit) can be purchased', function (): void {
    $ctx = makeMerchantActor();
    // Cooked + made-to-order ('ingredient') are recipe/kitchen-driven (shelf
    // filled by production, not bought); untracked holds no stock. All refused.
    foreach (['cooked', 'ingredient', 'untracked'] as $mode) {
        $product = grnProduct($ctx, ['stock_mode' => $mode]);
        $this->postJson('/api/purchase-receipts', [
            'lines' => [['item_type' => 'product', 'item_uuid' => $product->uuid, 'quantity' => '5', 'line_cost' => '4']],
        ])->assertStatus(422);
    }

    // A ready/bought-in (unit) product IS accepted, and so is a physical item
    // (which is unit-mode + internal) — both belong on a purchase receipt.
    $boughtIn = grnProduct($ctx, ['stock_mode' => 'unit', 'name' => 'Cola Can']);
    $physical = grnProduct($ctx, ['stock_mode' => 'unit', 'is_internal' => true, 'name' => 'Paper Cup']);
    $this->postJson('/api/purchase-receipts', [
        'lines' => [
            ['item_type' => 'product', 'item_uuid' => $boughtIn->uuid, 'quantity' => '5', 'line_cost' => '4'],
            ['item_type' => 'product', 'item_uuid' => $physical->uuid, 'quantity' => '5', 'line_cost' => '4'],
        ],
    ])->assertCreated();

    // Only the bought-in/physical receipt persisted; the 3 refused booked nothing.
    expect(PurchaseReceipt::query()->count())->toBe(1);
});

// ===================== AP — supplier credit / accounts payable =====================

it('records a cash receipt as fully paid by default', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);

    $res = $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '30']],
    ])->assertCreated();

    expect($res->json('data.is_credit'))->toBeFalse();
    expect($res->json('data.payment_status'))->toBe('paid');
    expect($res->json('data.amount_paid'))->toBe('30.000');
    expect($res->json('data.balance_due'))->toBe('0.000');
});

it('records a credit receipt as unpaid with the whole balance outstanding', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);

    $res = $this->postJson('/api/purchase-receipts', [
        'is_credit' => true,
        'due_date' => '2026-09-01',
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '30']],
    ])->assertCreated();

    expect($res->json('data.is_credit'))->toBeTrue();
    expect($res->json('data.payment_status'))->toBe('unpaid');
    expect($res->json('data.amount_paid'))->toBe('0.000');
    expect($res->json('data.balance_due'))->toBe('30.000');
    expect($res->json('data.due_date'))->toBe('2026-09-01');

    // The cost STILL booked its expense at receive (credit defers cash, not P&L).
    expect((float) Expense::query()->where('category', 'ingredients')->sum('amount'))->toBe(30.0);
});

it('records a partial payment, stays partial, and books no new expense', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);
    $created = $this->postJson('/api/purchase-receipts', [
        'is_credit' => true,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '30']],
    ])->assertCreated();
    $uuid = $created->json('data.uuid');

    $expensesBefore = Expense::query()->count();

    $res = $this->postJson("/api/purchase-receipts/{$uuid}/payments", [
        'amount' => '10', 'method' => 'cash', 'note' => 'first installment',
    ])->assertOk();

    expect($res->json('data.payment_status'))->toBe('partial');
    expect($res->json('data.amount_paid'))->toBe('10.000');
    expect($res->json('data.balance_due'))->toBe('20.000');
    expect($res->json('data.payments'))->toHaveCount(1);
    expect($res->json('data.payments.0.amount'))->toBe('10.000');
    expect($res->json('data.payments.0.balance_after'))->toBe('20.000');
    expect($res->json('data.payments.0.method'))->toBe('cash');

    // A payment is a settlement, not a P&L event — no expense was booked.
    expect(Expense::query()->count())->toBe($expensesBefore);
});

it('settles a credit receipt through successive partial payments', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);
    $uuid = $this->postJson('/api/purchase-receipts', [
        'is_credit' => true,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '30']],
    ])->assertCreated()->json('data.uuid');

    $this->postJson("/api/purchase-receipts/{$uuid}/payments", ['amount' => '10'])->assertOk();
    $res = $this->postJson("/api/purchase-receipts/{$uuid}/payments", ['amount' => '20'])->assertOk();

    expect($res->json('data.payment_status'))->toBe('paid');
    expect($res->json('data.amount_paid'))->toBe('30.000');
    expect($res->json('data.balance_due'))->toBe('0.000');
    expect($res->json('data.payments'))->toHaveCount(2);
});

it('rejects a payment exceeding the outstanding balance and records nothing', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);
    $uuid = $this->postJson('/api/purchase-receipts', [
        'is_credit' => true,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '30']],
    ])->assertCreated()->json('data.uuid');

    $this->postJson("/api/purchase-receipts/{$uuid}/payments", ['amount' => '40'])->assertStatus(422);

    $receipt = PurchaseReceipt::query()->where('uuid', $uuid)->firstOrFail();
    expect((string) $receipt->amount_paid)->toBe('0.000');
    expect($receipt->payments()->count())->toBe(0);
});

it('rejects a payment against an already fully-paid receipt', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);
    // A cash receipt is settled in full at receive — nothing left to pay.
    $uuid = $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '30']],
    ])->assertCreated()->json('data.uuid');

    $this->postJson("/api/purchase-receipts/{$uuid}/payments", ['amount' => '5'])->assertStatus(422);
});

it('lists only outstanding receipts when filtered', function (): void {
    $ctx = makeMerchantActor();
    $tomato = grnIngredient($ctx);
    // One cash (paid) + one credit (unpaid).
    $this->postJson('/api/purchase-receipts', [
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '30']],
    ])->assertCreated();
    $creditUuid = $this->postJson('/api/purchase-receipts', [
        'is_credit' => true,
        'lines' => [['item_type' => 'ingredient', 'item_uuid' => $tomato->uuid, 'quantity' => '10', 'line_cost' => '30']],
    ])->assertCreated()->json('data.uuid');

    expect($this->getJson('/api/purchase-receipts')->json('data'))->toHaveCount(2);

    $outstanding = $this->getJson('/api/purchase-receipts?payment_status=outstanding')->assertOk();
    expect($outstanding->json('data'))->toHaveCount(1);
    expect($outstanding->json('data.0.uuid'))->toBe($creditUuid);
});

it('refuses recording a payment against another company receipt', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = PurchaseReceipt::query()->create([
        'company_id' => $other->id, 'items_total' => '30.000', 'charges_total' => '0.000',
        'grand_total' => '30.000', 'status' => 'received', 'is_credit' => true,
        'amount_paid' => '0.000', 'payment_status' => 'unpaid', 'received_at' => now(),
    ]);

    $this->postJson("/api/purchase-receipts/{$foreign->uuid}/payments", ['amount' => '5'])->assertStatus(404);
    expect($foreign->payments()->count())->toBe(0);
});
