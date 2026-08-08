<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyShortfallReview;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('lists only genuine tenant shortfalls with exact parsed values and correct status totals', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create(['name' => 'Cafe points']);
    $account = LoyaltyAccount::factory()
        ->for($ctx['company'], 'company')
        ->for($customer, 'customer')
        ->for($rule, 'rule')
        ->withPoints(7)
        ->create(['stamp_count' => 2]);
    $order = Order::factory()->voided()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create(['customer_id' => $customer->id, 'receipt_number' => 'R-100']);

    $marker = LoyaltyTransaction::factory()
        ->for($ctx['company'], 'company')
        ->for($account, 'account')
        ->create([
            'points_delta' => 0,
            'stamps_delta' => 0,
            'balance_after_points' => 7,
            'balance_after_stamps' => 2,
            'order_id' => $order->id,
            'reason' => LoyaltyTransaction::SHORTFALL_REASON_PREFIX
                .' requested points=100 stamps=3; applied points=30 stamps=1; shortfall points=70 stamps=2; trigger=stale balance',
        ]);

    // Prefix alone is not enough: a balance-moving adjustment is not a
    // shortfall review marker.
    LoyaltyTransaction::factory()
        ->for($ctx['company'], 'company')
        ->for($account, 'account')
        ->create(['reason' => LoyaltyTransaction::SHORTFALL_REASON_PREFIX.' not a marker']);

    $foreignCompany = Company::factory()->create();
    $foreignCustomer = Customer::factory()->for($foreignCompany, 'company')->create();
    $foreignRule = LoyaltyRule::factory()->for($foreignCompany, 'company')->create();
    $foreignAccount = LoyaltyAccount::factory()
        ->for($foreignCompany, 'company')
        ->for($foreignCustomer, 'customer')
        ->for($foreignRule, 'rule')
        ->create();
    LoyaltyTransaction::factory()
        ->for($foreignCompany, 'company')
        ->for($foreignAccount, 'account')
        ->create([
            'points_delta' => 0,
            'stamps_delta' => 0,
            'reason' => LoyaltyTransaction::SHORTFALL_REASON_PREFIX
                .' requested points=1 stamps=0; applied points=0 stamps=0; shortfall points=1 stamps=0; trigger=test',
        ]);

    $response = $this->getJson('/api/loyalty/shortfalls')->assertOk();
    $response->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.transaction_uuid', $marker->uuid)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.requested.points', 100)
        ->assertJsonPath('data.0.requested.stamps', 3)
        ->assertJsonPath('data.0.applied.points', 30)
        ->assertJsonPath('data.0.shortfall.points', 70)
        ->assertJsonPath('data.0.shortfall.stamps', 2)
        ->assertJsonPath('data.0.customer.uuid', $customer->uuid)
        ->assertJsonPath('data.0.rule.name', 'Cafe points')
        ->assertJsonPath('data.0.order.receipt_number', 'R-100')
        ->assertJsonPath('data.0.order.status', 'void');

    $this->getJson('/api/loyalty/shortfalls?status=resolved')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
    $this->getJson('/api/loyalty/shortfalls?status=unsupported')->assertStatus(422);
});

it('resolves a shortfall once while leaving balances and the append-only ledger unchanged', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();
    $account = LoyaltyAccount::factory()
        ->for($ctx['company'], 'company')
        ->for($customer, 'customer')
        ->for($rule, 'rule')
        ->withPoints(12)
        ->create(['stamp_count' => 4]);
    $marker = LoyaltyTransaction::factory()
        ->for($ctx['company'], 'company')
        ->for($account, 'account')
        ->create([
            'points_delta' => 0,
            'stamps_delta' => 0,
            'balance_after_points' => 12,
            'balance_after_stamps' => 4,
            'reason' => LoyaltyTransaction::SHORTFALL_REASON_PREFIX
                .' requested points=20 stamps=5; applied points=8 stamps=1; shortfall points=12 stamps=4; trigger=test',
        ]);
    $ordinary = LoyaltyTransaction::factory()
        ->for($ctx['company'], 'company')
        ->for($account, 'account')
        ->create();
    $ledgerCount = LoyaltyTransaction::query()->count();

    $this->postJson("/api/loyalty/shortfalls/{$marker->uuid}/resolve", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['resolution_note']);

    $response = $this->postJson("/api/loyalty/shortfalls/{$marker->uuid}/resolve", [
        'resolution_note' => 'Confirmed as an offline balance race.',
    ])->assertOk();
    $response->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.resolution.note', 'Confirmed as an offline balance race.')
        ->assertJsonPath('data.resolution.resolved_by', $ctx['user']->name);

    expect(LoyaltyTransaction::query()->count())->toBe($ledgerCount)
        ->and(LoyaltyShortfallReview::query()->count())->toBe(1)
        ->and($account->fresh()->point_balance)->toBe(12)
        ->and($account->fresh()->stamp_count)->toBe(4)
        ->and($marker->fresh()->points_delta)->toBe(0)
        ->and($marker->fresh()->reason)->toBe($marker->reason);
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'loyalty.shortfall.resolved',
        'auditable_type' => LoyaltyShortfallReview::class,
    ]);

    // HTTP retries are idempotent: the first resolution is immutable.
    $this->postJson("/api/loyalty/shortfalls/{$marker->uuid}/resolve", [
        'resolution_note' => 'A different retry note.',
    ])->assertOk()->assertJsonPath('data.resolution.note', 'Confirmed as an offline balance race.');
    expect(LoyaltyShortfallReview::query()->count())->toBe(1)
        ->and(DB::table('pos_audit_logs')->where('event', 'loyalty.shortfall.resolved')->count())->toBe(1);

    $this->getJson('/api/loyalty/shortfalls?status=pending')
        ->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/loyalty/shortfalls?status=resolved')
        ->assertOk()->assertJsonPath('meta.total', 1);

    $this->postJson("/api/loyalty/shortfalls/{$ordinary->uuid}/resolve", [
        'resolution_note' => 'Not a genuine marker.',
    ])->assertStatus(422);
});

it('lets loyalty viewers inspect shortfalls but only managers resolve them', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();
    $account = LoyaltyAccount::factory()
        ->for($ctx['company'], 'company')
        ->for($customer, 'customer')
        ->for($rule, 'rule')
        ->create();
    $marker = LoyaltyTransaction::factory()
        ->for($ctx['company'], 'company')
        ->for($account, 'account')
        ->create([
            'points_delta' => 0,
            'stamps_delta' => 0,
            'reason' => LoyaltyTransaction::SHORTFALL_REASON_PREFIX
                .' requested points=1 stamps=0; applied points=0 stamps=0; shortfall points=1 stamps=0; trigger=test',
        ]);

    $this->getJson('/api/loyalty/shortfalls')->assertOk();
    $this->postJson("/api/loyalty/shortfalls/{$marker->uuid}/resolve", [
        'resolution_note' => 'Viewer must not resolve.',
    ])->assertForbidden();
    expect(LoyaltyShortfallReview::query()->count())->toBe(0);
});

it('does not expose or resolve a shortfall owned by another tenant', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);

    $foreignCompany = Company::factory()->create();
    $foreignCustomer = Customer::factory()->for($foreignCompany, 'company')->create();
    $foreignRule = LoyaltyRule::factory()->for($foreignCompany, 'company')->create();
    $foreignAccount = LoyaltyAccount::factory()
        ->for($foreignCompany, 'company')
        ->for($foreignCustomer, 'customer')
        ->for($foreignRule, 'rule')
        ->create();
    $foreignMarker = LoyaltyTransaction::factory()
        ->for($foreignCompany, 'company')
        ->for($foreignAccount, 'account')
        ->create([
            'points_delta' => 0,
            'stamps_delta' => 0,
            'reason' => LoyaltyTransaction::SHORTFALL_REASON_PREFIX
                .' requested points=5 stamps=0; applied points=0 stamps=0; shortfall points=5 stamps=0; trigger=test',
        ]);

    $this->getJson('/api/loyalty/shortfalls?status=all')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->postJson("/api/loyalty/shortfalls/{$foreignMarker->uuid}/resolve", [
        'resolution_note' => 'Must not cross tenant boundaries.',
    ])->assertNotFound();

    expect(LoyaltyShortfallReview::query()->count())->toBe(0)
        ->and(DB::table('pos_audit_logs')->where('event', 'loyalty.shortfall.resolved')->count())->toBe(0);
});
