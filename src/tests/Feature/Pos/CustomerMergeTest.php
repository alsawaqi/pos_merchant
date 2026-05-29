<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\CustomerWalletLedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * Phase 6a — customer merge coverage.
 *
 * The survivor is the route customer; the body's source_uuid is the duplicate
 * folded in + retired. Orders/plates/loyalty/wallet re-point to the survivor;
 * overlapping loyalty rules sum; all the source's plates re-point; the source
 * is soft-deleted. Gated by customers.manage.
 */
function mergeCustomers(string $survivorUuid, string $sourceUuid): TestResponse
{
    return postJson("/api/customers/{$survivorUuid}/merge", ['source_uuid' => $sourceUuid]);
}

it('merges orders, plates, wallet, and loyalty into the survivor then retires the source', function (): void {
    $ctx = makeMerchantActor();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();

    $survivor = Customer::factory()->for($ctx['company'], 'company')->create(['wallet_balance' => '2.000']);
    $source = Customer::factory()->for($ctx['company'], 'company')->create(['wallet_balance' => '5.000']);

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create(['customer_id' => $source->id]);
    CustomerVehiclePlate::factory()->for($ctx['company'], 'company')->create(['customer_id' => $source->id, 'plate_number' => 'SRC-1']);
    $acct = LoyaltyAccount::factory()->for($ctx['company'], 'company')->create([
        'customer_id' => $source->id, 'loyalty_rule_id' => $rule->id, 'stamp_count' => 4, 'point_balance' => 40,
    ]);
    LoyaltyTransaction::factory()->for($ctx['company'], 'company')->create(['loyalty_account_id' => $acct->id]);
    CustomerWalletLedgerEntry::factory()->for($ctx['company'], 'company')->create(['customer_id' => $source->id]);

    $res = mergeCustomers($survivor->uuid, $source->uuid)->assertOk();

    expect($res->json('summary.orders_moved'))->toBe(1);
    expect($res->json('summary.plates_moved'))->toBe(1);
    expect($res->json('summary.loyalty_accounts_moved'))->toBe(1);
    expect($res->json('summary.wallet_entries_moved'))->toBe(1);

    $this->assertDatabaseHas('pos_orders', ['customer_id' => $survivor->id]);
    $this->assertDatabaseHas('pos_customer_vehicle_plates', ['customer_id' => $survivor->id, 'plate_number' => 'SRC-1']);
    $this->assertDatabaseHas('pos_loyalty_accounts', ['id' => $acct->id, 'customer_id' => $survivor->id]);
    $this->assertDatabaseHas('pos_loyalty_transactions', ['loyalty_account_id' => $acct->id]); // followed the moved account

    // Wallet folded: 2 + 5 = 7.
    expect((string) $survivor->fresh()->wallet_balance)->toBe('7.000');

    // Source soft-deleted; survivor alive.
    expect(Customer::find($source->id))->toBeNull();
    expect(Customer::withTrashed()->find($source->id))->not->toBeNull();
    expect(Customer::find($survivor->id))->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'customers.merged', 'auditable_id' => $survivor->id]);
});

it('sums loyalty balances when both customers have an account for the same rule', function (): void {
    $ctx = makeMerchantActor();
    $rule = LoyaltyRule::factory()->for($ctx['company'], 'company')->create();

    $survivor = Customer::factory()->for($ctx['company'], 'company')->create();
    $source = Customer::factory()->for($ctx['company'], 'company')->create();

    $survivorAcct = LoyaltyAccount::factory()->for($ctx['company'], 'company')->create([
        'customer_id' => $survivor->id, 'loyalty_rule_id' => $rule->id, 'stamp_count' => 3, 'point_balance' => 30,
    ]);
    $sourceAcct = LoyaltyAccount::factory()->for($ctx['company'], 'company')->create([
        'customer_id' => $source->id, 'loyalty_rule_id' => $rule->id, 'stamp_count' => 2, 'point_balance' => 20,
    ]);
    LoyaltyTransaction::factory()->for($ctx['company'], 'company')->create(['loyalty_account_id' => $sourceAcct->id]);

    $res = mergeCustomers($survivor->uuid, $source->uuid)->assertOk();

    expect($res->json('summary.loyalty_accounts_merged'))->toBe(1);

    $survivorAcct->refresh();
    expect($survivorAcct->stamp_count)->toBe(5);
    expect($survivorAcct->point_balance)->toBe(50);
    expect(LoyaltyAccount::find($sourceAcct->id))->toBeNull(); // source account folded away
    // The source account's transaction was re-pointed to the survivor's account.
    $this->assertDatabaseHas('pos_loyalty_transactions', ['loyalty_account_id' => $survivorAcct->id]);
});

it('moves all of the source customer plates to the survivor', function (): void {
    $ctx = makeMerchantActor();
    $survivor = Customer::factory()->for($ctx['company'], 'company')->create();
    $source = Customer::factory()->for($ctx['company'], 'company')->create();

    // (company_id, plate_number) is unique, so the survivor + source can only
    // ever hold DISTINCT plates — all of the source's move across.
    CustomerVehiclePlate::factory()->for($ctx['company'], 'company')->create(['customer_id' => $survivor->id, 'plate_number' => 'KEEP-1']);
    CustomerVehiclePlate::factory()->for($ctx['company'], 'company')->create(['customer_id' => $source->id, 'plate_number' => 'MOVE-1']);
    CustomerVehiclePlate::factory()->for($ctx['company'], 'company')->create(['customer_id' => $source->id, 'plate_number' => 'MOVE-2']);

    $res = mergeCustomers($survivor->uuid, $source->uuid)->assertOk();

    expect($res->json('summary.plates_moved'))->toBe(2);
    expect(CustomerVehiclePlate::where('customer_id', $survivor->id)->pluck('plate_number')->sort()->values()->all())
        ->toBe(['KEEP-1', 'MOVE-1', 'MOVE-2']);
});

it('refuses to merge a customer into itself', function (): void {
    $ctx = makeMerchantActor();
    $c = Customer::factory()->for($ctx['company'], 'company')->create();

    mergeCustomers($c->uuid, $c->uuid)->assertStatus(422);
});

it('refuses a source customer from another company', function (): void {
    $ctx = makeMerchantActor();
    $survivor = Customer::factory()->for($ctx['company'], 'company')->create();

    $other = Company::factory()->create();
    $foreign = Customer::factory()->for($other, 'company')->create();

    mergeCustomers($survivor->uuid, $foreign->uuid)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_uuid']);
});

it('requires customers.manage permission', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $survivor = Customer::factory()->for($ctx['company'], 'company')->create();
    $source = Customer::factory()->for($ctx['company'], 'company')->create();

    mergeCustomers($survivor->uuid, $source->uuid)->assertForbidden();
});
