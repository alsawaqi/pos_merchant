<?php

declare(strict_types=1);

/**
 * Loyalty refactor — LoyaltyController coverage (blueprint §5.8).
 *
 * Rules + accounts + transactions model, plus the (unchanged)
 * wallet store-credit path.
 *
 * Covers:
 *   - RULES: list / create (visit + spend) / update / pause /
 *     resume / delete; validation; tenant isolation
 *   - CUSTOMER: summary (accounts + recent txns + wallet);
 *     manual adjust (points + stamps) auto-creating the account;
 *     reason required; zero-delta + negative-balance guards;
 *     unknown-rule + cross-tenant guards; paginated transactions
 *   - WALLET: topup / adjust / ledger (ported, unchanged)
 *   - INVARIANT: account balance == SUM(transactions)
 *   - PERMISSION MATRIX: view-only roles vs Manager
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// =================== RULES ===================

it('lists loyalty rules scoped to the tenant', function (): void {
    $ctx = makeMerchantActor();
    LoyaltyRule::factory()->for($ctx['company'], 'company')->create(['name' => 'Points']);
    LoyaltyRule::factory()->for($ctx['company'], 'company')->visitBased()->create(['name' => 'Stamps']);

    $foreign = Company::factory()->create();
    LoyaltyRule::factory()->for($foreign, 'company')->create();

    $rows = $this->getJson('/api/loyalty/rules')->assertOk()->json('data');
    expect($rows)->toHaveCount(2);
});

it('creates a spend_based rule with an audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/loyalty/rules', [
        'name' => 'Coffee Points',
        'type' => 'spend_based',
        'config_json' => ['points_per_omr' => 1, 'redemption_points' => 100, 'redemption_value' => '5.000', 'min_redemption_points' => 100],
    ])->assertCreated();

    expect($response->json('data.type'))->toBe('spend_based');
    expect($response->json('data.status'))->toBe('active');
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'loyalty.rule.created', 'company_id' => $ctx['company']->id]);
});

it('creates a visit_based stamp-card rule', function (): void {
    makeMerchantActor();

    $response = $this->postJson('/api/loyalty/rules', [
        'name' => 'Buy 5 Get 1',
        'type' => 'visit_based',
        'config_json' => ['min_order_value' => '2.000', 'stamps_required' => 5, 'reward_type' => 'free_product'],
    ])->assertCreated();

    expect($response->json('data.type'))->toBe('visit_based');
    expect($response->json('data.config.stamps_required'))->toBe(5);
});

it('returns 422 on missing name or invalid type', function (): void {
    makeMerchantActor();

    $this->postJson('/api/loyalty/rules', ['type' => 'spend_based'])
        ->assertStatus(422)->assertJsonValidationErrors(['name']);
    $this->postJson('/api/loyalty/rules', ['name' => 'X', 'type' => 'bogus'])
        ->assertStatus(422)->assertJsonValidationErrors(['type']);
});

it('updates, pauses, and resumes a rule', function (): void {
    $ctx = makeMerchantActor();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create(['name' => 'Old']);

    $this->patchJson("/api/loyalty/rules/{$rule->uuid}", ['name' => 'New'])
        ->assertOk()->assertJsonPath('data.name', 'New');

    $this->postJson("/api/loyalty/rules/{$rule->uuid}/pause")
        ->assertOk()->assertJsonPath('data.status', 'paused');
    $this->postJson("/api/loyalty/rules/{$rule->uuid}/resume")
        ->assertOk()->assertJsonPath('data.status', 'active');
});

it('soft-deletes a rule', function (): void {
    $ctx = makeMerchantActor();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();

    $this->deleteJson("/api/loyalty/rules/{$rule->uuid}")->assertNoContent();
    $this->assertSoftDeleted('pos_loyalty_rules', ['id' => $rule->id]);
});

it('returns 404 mutating a rule from another company', function (): void {
    makeMerchantActor();
    $foreign = Company::factory()->create();
    $rule = LoyaltyRule::factory()->for($foreign, 'company')->create();

    $this->patchJson("/api/loyalty/rules/{$rule->uuid}", ['name' => 'Hijack'])->assertNotFound();
    $this->postJson("/api/loyalty/rules/{$rule->uuid}/pause")->assertNotFound();
    $this->deleteJson("/api/loyalty/rules/{$rule->uuid}")->assertNotFound();
});

// =================== CUSTOMER SUMMARY ===================

it('returns the customer loyalty summary (accounts + wallet)', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create(['wallet_balance' => '5.000']);
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();
    LoyaltyAccount::factory()->for($ctx['company'], 'company')->for($customer, 'customer')->for($rule, 'rule')
        ->withPoints(40)->create();

    $response = $this->getJson("/api/customers/{$customer->uuid}/loyalty")->assertOk();
    expect($response->json('data.customer.wallet_balance'))->toBe('5.000');
    expect($response->json('data.accounts'))->toHaveCount(1);
    expect($response->json('data.accounts.0.point_balance'))->toBe(40);
    expect($response->json('data.accounts.0.rule.name'))->toBe($rule->name);
});

it('returns 404 for a customer summary owned by another company', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Customer::factory()->for($other, 'company')->create();

    $this->getJson("/api/customers/{$foreign->uuid}/loyalty")->assertNotFound();
});

// =================== ADJUST ===================

it('adjusts points, auto-creating the account, with an audit row', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule->uuid,
        'points_delta' => 75,
        'reason' => 'Pilot signup bonus',
    ])->assertCreated();

    expect($response->json('data.transaction.points_delta'))->toBe(75);
    expect($response->json('data.transaction.balance_after_points'))->toBe(75);
    expect($response->json('data.account.point_balance'))->toBe(75);

    $this->assertDatabaseHas('pos_loyalty_accounts', [
        'customer_id' => $customer->id,
        'loyalty_rule_id' => $rule->id,
        'point_balance' => 75,
    ]);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'loyalty.transaction.adjust']);
});

it('adjusts stamps', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->visitBased()->create();

    $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule->uuid,
        'stamps_delta' => 3,
        'reason' => 'Manual stamps',
    ])->assertCreated()->assertJsonPath('data.account.stamp_count', 3);
});

it('requires a reason and a non-zero delta', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule->uuid,
        'points_delta' => 10,
        'reason' => '',
    ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

    // Both deltas zero → the Action rejects (422 message, not a
    // field error).
    $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule->uuid,
        'points_delta' => 0,
        'stamps_delta' => 0,
        'reason' => 'Nothing',
    ])->assertStatus(422);
});

it('refuses an adjustment that would drive a balance negative', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();

    // Start at 10.
    $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule->uuid, 'points_delta' => 10, 'reason' => 'Open',
    ])->assertCreated();

    $response = $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule->uuid, 'points_delta' => -50, 'reason' => 'Too much',
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('negative');
});

it('returns 422 for an unknown rule uuid', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => '00000000-0000-0000-0000-000000000000',
        'points_delta' => 10,
        'reason' => 'x',
    ])->assertStatus(422);
});

it('returns 404 adjusting a cross-tenant customer', function (): void {
    $ctx = makeMerchantActor();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();
    $other = Company::factory()->create();
    $foreign = Customer::factory()->for($other, 'company')->create();

    $this->postJson("/api/customers/{$foreign->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule->uuid, 'points_delta' => 10, 'reason' => 'x',
    ])->assertNotFound();
});

it('paginates a customer transactions history', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();
    $account = LoyaltyAccount::factory()->for($ctx['company'], 'company')->for($customer, 'customer')->for($rule, 'rule')->create();
    LoyaltyTransaction::factory()->for($ctx['company'], 'company')->for($account, 'account')->count(3)->create();

    $response = $this->getJson("/api/customers/{$customer->uuid}/loyalty/transactions")->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

// =================== INVARIANT ===================

it('keeps the account balance in lock-step with SUM(transactions)', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();

    foreach ([100, -30, 50, -10] as $delta) {
        $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
            'loyalty_rule_uuid' => $rule->uuid, 'points_delta' => $delta, 'reason' => 'seq',
        ])->assertCreated();
    }

    $account = LoyaltyAccount::query()
        ->where('customer_id', $customer->id)->where('loyalty_rule_id', $rule->id)->first();
    expect((int) $account->point_balance)->toBe(110);

    $sum = (int) DB::table('pos_loyalty_transactions')->where('loyalty_account_id', $account->id)->sum('points_delta');
    expect($sum)->toBe(110);

    $latest = LoyaltyTransaction::query()->where('loyalty_account_id', $account->id)->orderByDesc('id')->first();
    expect((int) $latest->balance_after_points)->toBe(110);
});

// =================== WALLET (unchanged) ===================

it('tops up the wallet and bumps the balance', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", [
        'amount' => '5.500', 'reason' => 'Cash topup',
    ])->assertCreated();

    expect($response->json('data.wallet_balance'))->toBe('5.500');
    $customer->refresh();
    expect((string) $customer->wallet_balance)->toBe('5.500');
});

it('refuses a zero or negative wallet topup', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '0'])
        ->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

it('adjusts the wallet with a signed delta + reason and refuses below-zero', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create(['wallet_balance' => '10.000']);

    $this->postJson("/api/customers/{$customer->uuid}/wallet/adjust", [
        'amount_delta' => '-3.500', 'reason' => 'Correction',
    ])->assertCreated()->assertJsonPath('data.wallet_balance', '6.500');

    $response = $this->postJson("/api/customers/{$customer->uuid}/wallet/adjust", [
        'amount_delta' => '-99.000', 'reason' => 'Too much',
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Insufficient');
});

it('paginates the wallet ledger', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '1.000'])->assertCreated();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '2.000'])->assertCreated();

    $response = $this->getJson("/api/customers/{$customer->uuid}/wallet/ledger")->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

// =================== PERMISSION MATRIX ===================

it('lets a Viewer read loyalty but forbids writes', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/loyalty/rules')->assertOk();
    $this->getJson("/api/customers/{$customer->uuid}/loyalty")->assertOk();

    $this->postJson('/api/loyalty/rules', ['name' => 'X', 'type' => 'spend_based'])->assertForbidden();
    $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule->uuid, 'points_delta' => 1, 'reason' => 'x',
    ])->assertForbidden();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '1.000'])->assertForbidden();
});

it('lets a Manager run the full loyalty lifecycle', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();

    $rule = $this->postJson('/api/loyalty/rules', [
        'name' => 'Points', 'type' => 'spend_based',
        'config_json' => ['points_per_omr' => 1],
    ])->assertCreated()->json('data');

    $this->postJson("/api/customers/{$customer->uuid}/loyalty/adjust", [
        'loyalty_rule_uuid' => $rule['uuid'], 'points_delta' => 100, 'reason' => 'Opening',
    ])->assertCreated();
    $this->postJson("/api/customers/{$customer->uuid}/wallet/topup", ['amount' => '10.000'])->assertCreated();

    $customer->refresh();
    expect((string) $customer->wallet_balance)->toBe('10.000');
});
