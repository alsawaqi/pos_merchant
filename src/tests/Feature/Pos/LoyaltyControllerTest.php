<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 6b LoyaltyController + the Loyalty
 * Actions (config + point/wallet writers + wrappers).
 *
 * Covers:
 *   - CONFIG: GET lazy-creates with production defaults; PATCH
 *     upserts with diff-aware audit; cross-tenant isolation;
 *     validation (negative rates rejected); permission gates
 *   - CUSTOMER SUMMARY: returns balances + config + 5 most-
 *     recent entries per ledger; cross-tenant 404
 *   - POINT ADJUST: writes ledger row + audit row + bumps
 *     customers.points_balance atomically; reason required;
 *     refuses balance-below-zero; cross-tenant 404
 *   - WALLET TOPUP: positive-only; correct entry_type; balance
 *     bumped; cross-tenant 404
 *   - WALLET ADJUST: signed; reason required; refuses balance-
 *     below-zero
 *   - LEDGER PAGINATION: paginated; tenant-scoped; cross-
 *     tenant returns 404 on the URL
 *   - INVARIANT: a mixed sequence of adjustments + top-ups
 *     keeps customers.points_balance == SUM(point_ledger) and
 *     customers.wallet_balance == SUM(wallet_ledger)
 *   - PERMISSION MATRIX: Viewer view-only, CashierSupervisor
 *     view-only, InventoryManager view-only, Manager full
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerLoyaltyConfig;
use App\Models\CustomerPointLedgerEntry;
use App\Models\CustomerWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// =================== CONFIG ===================

it('GET /api/loyalty/config lazy-creates a config with production defaults on first read', function (): void {
    $ctx = makeMerchantActor();

    expect(CustomerLoyaltyConfig::query()->count())->toBe(0);

    $response = $this->getJson('/api/loyalty/config')->assertOk();

    expect($response->json('data.points_per_omr'))->toBe(0);
    expect($response->json('data.baisas_per_point'))->toBe(10);
    expect($response->json('data.is_active'))->toBeFalse();

    // Persisted singleton.
    expect(CustomerLoyaltyConfig::query()->where('company_id', $ctx['company']->id)->count())->toBe(1);
});

it('PATCH /api/loyalty/config upserts with audit row', function (): void {
    $ctx = makeMerchantActor();

    $this->patchJson('/api/loyalty/config', [
        'points_per_omr' => 2,
        'baisas_per_point' => 5,
        'is_active' => true,
    ])->assertOk()
        ->assertJsonPath('data.points_per_omr', 2)
        ->assertJsonPath('data.baisas_per_point', 5)
        ->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'loyalty.config.updated',
        'company_id' => $ctx['company']->id,
    ]);

    // Second PATCH with identical values should be a no-op
    // (no second audit row).
    $this->patchJson('/api/loyalty/config', [
        'points_per_omr' => 2,
    ])->assertOk();

    $count = DB::table('pos_audit_logs')
        ->where('event', 'loyalty.config.updated')
        ->where('company_id', $ctx['company']->id)
        ->count();
    expect($count)->toBe(1);
});

it('returns 422 when config rates are negative', function (): void {
    makeMerchantActor();

    $this->patchJson('/api/loyalty/config', ['points_per_omr' => -1])
        ->assertStatus(422)->assertJsonValidationErrors(['points_per_omr']);
    $this->patchJson('/api/loyalty/config', ['baisas_per_point' => -5])
        ->assertStatus(422)->assertJsonValidationErrors(['baisas_per_point']);
});

it('isolates config per tenant', function (): void {
    $ctx1 = makeMerchantActor();
    $this->patchJson('/api/loyalty/config', ['points_per_omr' => 7])->assertOk();

    // Re-acting as a different tenant should NOT see the same config.
    $ctx2 = makeMerchantActor();
    $config2 = $this->getJson('/api/loyalty/config')->assertOk();
    expect($config2->json('data.points_per_omr'))->toBe(0);
    expect($config2->json('data.id'))->not->toBe(
        CustomerLoyaltyConfig::query()->where('company_id', $ctx1['company']->id)->value('id'),
    );
});

// =================== CUSTOMER SUMMARY ===================

it('GET /api/customers/{uuid}/loyalty returns balances + config + recent entries', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')
        ->create(['points_balance' => 100, 'wallet_balance' => '5.000']);
    // Two ledger entries to ensure "recent" surfaces them.
    CustomerPointLedgerEntry::factory()
        ->for($customer, 'customer')->for($ctx['company'], 'company')
        ->create(['points_delta' => 50, 'balance_after' => 50, 'reason' => 'opening']);
    CustomerPointLedgerEntry::factory()
        ->for($customer, 'customer')->for($ctx['company'], 'company')
        ->create(['points_delta' => 50, 'balance_after' => 100, 'reason' => 'second']);

    $response = $this->getJson("/api/customers/{$customer->uuid}/loyalty")->assertOk();
    expect($response->json('data.customer.points_balance'))->toBe(100);
    expect($response->json('data.customer.wallet_balance'))->toBe('5.000');
    expect($response->json('data.config'))->not->toBeNull();
    expect($response->json('data.recent_points'))->toHaveCount(2);
    expect($response->json('data.recent_wallet'))->toHaveCount(0);
});

it('returns 404 for a customer summary owned by another company', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Customer::factory()->for($other, 'company')->create();

    $this->getJson("/api/customers/{$foreign->uuid}/loyalty")->assertNotFound();
});

// =================== POINT ADJUST ===================

it('POST /points/adjust writes ledger + bumps customers.points_balance + audit (positive)', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson("/api/customers/{$customer->uuid}/points/adjust", [
        'points_delta' => 75,
        'reason' => 'Pilot signup bonus',
    ])->assertCreated();

    expect($response->json('data.points_balance'))->toBe(75);
    expect($response->json('data.entry.points_delta'))->toBe(75);
    expect($response->json('data.entry.balance_after'))->toBe(75);
    expect($response->json('data.entry.entry_type'))->toBe('adjustment');

    // Customer column matches.
    $customer->refresh();
    expect((int) $customer->points_balance)->toBe(75);

    // Single ledger row + audit row.
    expect(CustomerPointLedgerEntry::query()->where('customer_id', $customer->id)->count())->toBe(1);
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'loyalty.point_ledger.entry',
        'company_id' => $ctx['company']->id,
    ]);
});

it('POST /points/adjust supports a negative delta when balance is sufficient', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')
        ->create(['points_balance' => 100]);

    $this->postJson("/api/customers/{$customer->uuid}/points/adjust", [
        'points_delta' => -30,
        'reason' => 'Goodwill refund clawback',
    ])->assertCreated()->assertJsonPath('data.points_balance', 70);

    $customer->refresh();
    expect((int) $customer->points_balance)->toBe(70);
});

it('POST /points/adjust returns 422 when delta would drop balance below zero', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')
        ->create(['points_balance' => 10]);

    $response = $this->postJson("/api/customers/{$customer->uuid}/points/adjust", [
        'points_delta' => -50,
        'reason' => 'Way too aggressive',
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Insufficient');

    // Customer balance untouched on the rejected path.
    $customer->refresh();
    expect((int) $customer->points_balance)->toBe(10);
    expect(CustomerPointLedgerEntry::query()->where('customer_id', $customer->id)->count())->toBe(0);
});

it('POST /points/adjust returns 422 with no reason and not_in:0 on zero delta', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/customers/{$customer->uuid}/points/adjust", [
        'points_delta' => 50,
        'reason' => '',
    ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

    $this->postJson("/api/customers/{$customer->uuid}/points/adjust", [
        'points_delta' => 0,
        'reason' => 'Nothing',
    ])->assertStatus(422)->assertJsonValidationErrors(['points_delta']);
});

it('returns 404 when adjusting points on a cross-tenant customer', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Customer::factory()->for($other, 'company')->create();

    $this->postJson("/api/customers/{$foreign->uuid}/points/adjust", [
        'points_delta' => 100,
        'reason' => 'Hijack',
    ])->assertNotFound();
});

// =================== WALLET TOPUP ===================

it('POST /wallet/topup writes ledger + bumps customers.wallet_balance', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", [
        'amount' => '5.500',
        'reason' => 'Cash topup at counter',
    ])->assertCreated();

    expect($response->json('data.wallet_balance'))->toBe('5.500');
    expect($response->json('data.entry.amount_delta'))->toBe('5.500');
    expect($response->json('data.entry.entry_type'))->toBe('topup');

    $customer->refresh();
    expect((string) $customer->wallet_balance)->toBe('5.500');
});

it('POST /wallet/topup refuses zero or negative amounts', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", [
        'amount' => '0',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", [
        'amount' => '-1.000',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

// =================== WALLET ADJUST ===================

it('POST /wallet/adjust supports signed deltas with required reason', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')
        ->create(['wallet_balance' => '10.000']);

    // Negative adjust within balance.
    $this->postJson("/api/customers/{$customer->uuid}/wallet/adjust", [
        'amount_delta' => '-3.500',
        'reason' => 'Counter-side correction',
    ])->assertCreated()->assertJsonPath('data.wallet_balance', '6.500');

    $customer->refresh();
    expect((string) $customer->wallet_balance)->toBe('6.500');
});

it('POST /wallet/adjust returns 422 when delta would drop wallet below zero', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')
        ->create(['wallet_balance' => '1.000']);

    $response = $this->postJson("/api/customers/{$customer->uuid}/wallet/adjust", [
        'amount_delta' => '-2.000',
        'reason' => 'Too aggressive',
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Insufficient');

    $customer->refresh();
    expect((string) $customer->wallet_balance)->toBe('1.000');
});

// =================== LEDGER PAGINATION ===================

it('GET /points/ledger returns paginated entries scoped to the customer', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    CustomerPointLedgerEntry::factory()
        ->for($customer, 'customer')->for($ctx['company'], 'company')
        ->count(3)->create();

    // Another customer's entries must NOT leak in.
    $other = Customer::factory()->for($ctx['company'], 'company')->create();
    CustomerPointLedgerEntry::factory()
        ->for($other, 'customer')->for($ctx['company'], 'company')
        ->create();

    $response = $this->getJson("/api/customers/{$customer->uuid}/points/ledger")->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('current_page'))->toBe(1);
});

it('GET /wallet/ledger returns paginated entries scoped to the customer', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    CustomerWalletLedgerEntry::factory()
        ->for($customer, 'customer')->for($ctx['company'], 'company')
        ->count(2)->create();

    $response = $this->getJson("/api/customers/{$customer->uuid}/wallet/ledger")->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('returns 404 on a cross-tenant ledger URL', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Customer::factory()->for($other, 'company')->create();

    $this->getJson("/api/customers/{$foreign->uuid}/points/ledger")->assertNotFound();
    $this->getJson("/api/customers/{$foreign->uuid}/wallet/ledger")->assertNotFound();
});

// =================== INVARIANT: BALANCE = SUM(LEDGER) ===================

it('keeps customers.points_balance in lock-step with SUM(point_ledger) across a mixed sequence', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    // Sequence: +100 → -30 → +50 → -10. Expected: 110.
    $deltas = [
        ['points_delta' => 100, 'reason' => 'Opening'],
        ['points_delta' => -30, 'reason' => 'Clawback A'],
        ['points_delta' => 50, 'reason' => 'Promo'],
        ['points_delta' => -10, 'reason' => 'Clawback B'],
    ];
    foreach ($deltas as $payload) {
        $this->postJson("/api/customers/{$customer->uuid}/points/adjust", $payload)
            ->assertCreated();
    }

    $customer->refresh();
    expect((int) $customer->points_balance)->toBe(110);

    // SUM(ledger) also says 110.
    $sum = (int) DB::table('pos_customer_point_ledger')
        ->where('customer_id', $customer->id)
        ->sum('points_delta');
    expect($sum)->toBe(110);

    // The last entry's balance_after MUST equal the customer
    // column too — drift detector.
    $latest = CustomerPointLedgerEntry::query()
        ->where('customer_id', $customer->id)
        ->orderByDesc('id')
        ->first();
    expect((int) $latest->balance_after)->toBe(110);
});

it('keeps customers.wallet_balance in lock-step with SUM(wallet_ledger) across a mixed sequence', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    // Sequence: +5.000 (topup) → -1.500 (adjust) → +2.000 (topup) → -0.250 (adjust)
    // Expected: 5.250.
    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '5.000'])->assertCreated();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/adjust", ['amount_delta' => '-1.500', 'reason' => 'A'])->assertCreated();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '2.000'])->assertCreated();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/adjust", ['amount_delta' => '-0.250', 'reason' => 'B'])->assertCreated();

    $customer->refresh();
    expect((string) $customer->wallet_balance)->toBe('5.250');

    // SUM(ledger) also says 5.250 (compared via float for
    // tolerance; the column is decimal:3).
    $sum = (float) DB::table('pos_customer_wallet_ledger')
        ->where('customer_id', $customer->id)
        ->sum('amount_delta');
    expect($sum)->toBe(5.25);

    $latest = CustomerWalletLedgerEntry::query()
        ->where('customer_id', $customer->id)
        ->orderByDesc('id')
        ->first();
    expect((string) $latest->balance_after)->toBe('5.250');
});

// =================== PERMISSION MATRIX ===================

it('lets a Viewer GET config + summary + ledgers but forbids every write', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/loyalty/config')->assertOk();
    $this->getJson("/api/customers/{$customer->uuid}/loyalty")->assertOk();
    $this->getJson("/api/customers/{$customer->uuid}/points/ledger")->assertOk();
    $this->getJson("/api/customers/{$customer->uuid}/wallet/ledger")->assertOk();

    $this->patchJson('/api/loyalty/config', ['points_per_omr' => 1])->assertForbidden();
    $this->postJson("/api/customers/{$customer->uuid}/points/adjust", ['points_delta' => 1, 'reason' => 'x'])->assertForbidden();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '1.000'])->assertForbidden();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/adjust", ['amount_delta' => '1.000', 'reason' => 'x'])->assertForbidden();
});

it('lets a CashierSupervisor view balances but not write', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->getJson("/api/customers/{$customer->uuid}/loyalty")->assertOk();
    $this->postJson("/api/customers/{$customer->uuid}/points/adjust", ['points_delta' => 5, 'reason' => 'x'])
        ->assertForbidden();
});

it('lets an InventoryManager view balances but not write (view-only per role matrix)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->getJson("/api/customers/{$customer->uuid}/loyalty")->assertOk();
    $this->postJson("/api/customers/{$customer->uuid}/points/adjust", ['points_delta' => 5, 'reason' => 'x'])
        ->assertForbidden();
});

it('lets a Manager run the full loyalty lifecycle', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    // Config upsert
    $this->patchJson('/api/loyalty/config', [
        'points_per_omr' => 1,
        'is_active' => true,
    ])->assertOk();

    // Point adjust + wallet topup + wallet adjust
    $this->postJson("/api/customers/{$customer->uuid}/points/adjust", ['points_delta' => 100, 'reason' => 'Opening'])->assertCreated();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '10.000'])->assertCreated();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/adjust", ['amount_delta' => '-2.000', 'reason' => 'Correction'])->assertCreated();

    $customer->refresh();
    expect((int) $customer->points_balance)->toBe(100);
    expect((string) $customer->wallet_balance)->toBe('8.000');
});
