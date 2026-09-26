<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\CustomerWalletLedgerEntry;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// PostgreSQL is pre-built with the owning admin migrations, never a schema mirror.
uses(getenv('DB_CONNECTION') === 'pgsql' ? DatabaseTransactions::class : RefreshDatabase::class);

it('folds selected duplicate pairs with a complete append-only reference audit and repeat safety', function (): void {
    $ctx = makeMerchantActor();
    $company = $ctx['company'];
    $actor = $ctx['user'];
    $a = Customer::factory()->for($company, 'company')->create(['phone' => '+968 9000 0001', 'wallet_balance' => '2.000']);
    $b = Customer::factory()->for($company, 'company')->create(['phone' => '90000001', 'wallet_balance' => '5.000']);
    $r1 = LoyaltyRule::factory()->for($company, 'company')->create();
    $r2 = LoyaltyRule::factory()->for($company, 'company')->create();
    $aa = LoyaltyAccount::factory()->for($company, 'company')->create(['customer_id' => $a->id, 'loyalty_rule_id' => $r1->id, 'point_balance' => 200, 'stamp_count' => 10]);
    $ba = LoyaltyAccount::factory()->for($company, 'company')->create(['customer_id' => $b->id, 'loyalty_rule_id' => $r1->id, 'point_balance' => 100, 'stamp_count' => 5]);
    $bb = LoyaltyAccount::factory()->for($company, 'company')->create(['customer_id' => $b->id, 'loyalty_rule_id' => $r2->id, 'point_balance' => 40, 'stamp_count' => 2]);
    foreach ([$aa, $ba, $bb] as $account) {
        LoyaltyTransaction::factory()->for($company, 'company')->create(['loyalty_account_id' => $account->id]);
    }
    $order = Order::factory()->for($company, 'company')->for($ctx['branch'], 'branch')->create(['customer_id' => $b->id, 'status' => 'paid']);
    foreach ([[$a, 'SHARED'], [$b, 'SHARED'], [$b, 'SOURCE']] as [$customer, $plate]) {
        CustomerVehiclePlate::factory()->for($company, 'company')->create(['customer_id' => $customer->id, 'plate_number' => $plate]);
    }
    $wallet = CustomerWalletLedgerEntry::factory()->for($company, 'company')->create(['customer_id' => $b->id]);
    $tables = ['pos_customers', 'pos_orders', 'pos_loyalty_accounts', 'pos_loyalty_transactions', 'pos_customer_wallet_ledger', 'pos_customer_vehicle_plates'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $counts = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    $before = $snapshot();
    $beforeCounts = $counts();
    $options = ['--company' => $company->id, '--actor' => $actor->id];
    expect(Artisan::call('customers:merge-duplicates', $options))->toBe(0);
    fwrite(STDOUT, "\nMERGE_DRY_BEFORE\n".Artisan::output());
    expect($snapshot())->toBe($before);
    $since = now()->subSecond();
    expect(Artisan::call('customers:merge-duplicates', $options + ['--apply' => true, '--only' => $a->id.':'.$b->id]))->toBe(0);
    fwrite(STDOUT, "\nMERGE_APPLY\n".Artisan::output());
    app(MerchantTenantContext::class)->set($company->id);
    expect($a->fresh()->wallet_balance)->toBe('7.000');
    expect($aa->fresh()->point_balance)->toBe(300)->and($aa->fresh()->stamp_count)->toBe(15);
    expect($bb->fresh()->customer_id)->toBe($a->id);
    expect(LoyaltyTransaction::where('loyalty_account_id', $aa->id)->count())->toBe(2);
    expect(DB::table('pos_loyalty_transactions')->where('loyalty_account_id', $ba->id)->exists())->toBeFalse();
    expect($order->fresh()->customer_id)->toBe($a->id);
    expect($wallet->fresh()->customer_id)->toBe($a->id);
    expect(CustomerVehiclePlate::where('customer_id', $a->id)->orderBy('plate_number')->pluck('plate_number')->all())->toBe(['SHARED', 'SOURCE']);
    expect($b->fresh()->trashed())->toBeTrue()->and($b->fresh()->merged_into_customer_id)->toBe($a->id);
    expect($a->fresh()->updated_at->greaterThan($since))->toBeTrue();
    $audit = DB::table('pos_audit_logs')->where('event', 'customers.merged')->first();
    $values = json_decode($audit->new_values, true);
    expect($values['repointed_order_ids'])->toBe([$order->id]);
    expect($values['deleted_loyalty_accounts'])->toHaveCount(1);
    expect($values['deleted_loyalty_accounts'][0])->toMatchArray(['id' => $ba->id, 'points' => 100, 'stamps' => 5]);
    expect($values['deleted_plate_link_ids'])->toHaveCount(1);
    expect($values['survivor_balances_before']['wallet'])->toBe('2.000');
    expect($values['survivor_balances_after']['wallet'])->toBe('7.000');
    $after = $snapshot();
    expect(Artisan::call('customers:merge-duplicates', $options + ['--apply' => true, '--only' => $a->id.':'.$b->id]))->toBe(0);
    expect(Artisan::output())->toContain('already_merged');
    expect($snapshot())->toBe($after);
    expect(Artisan::call('customers:merge-duplicates', $options))->toBe(0);
    $dryAfter = Artisan::output();
    fwrite(STDOUT, "\nMERGE_DRY_AFTER\n".$dryAfter);
    expect($dryAfter)->toContain('0 duplicate groups');
    $afterCounts = $counts();
    foreach (['pos_customers', 'pos_orders', 'pos_loyalty_transactions', 'pos_customer_wallet_ledger'] as $table) {
        expect($afterCounts[$table])->toBe($beforeCounts[$table]);
    }
    expect($afterCounts['pos_loyalty_accounts'])->toBe($beforeCounts['pos_loyalty_accounts'] - 1);
    expect($afterCounts['pos_customer_vehicle_plates'])->toBe($beforeCounts['pos_customer_vehicle_plates'] - 1);
    fwrite(STDOUT, 'MERGE_COUNTS '.json_encode(['before' => $beforeCounts, 'after' => $afterCounts])."\n");
});

it('refuses every unfinished bill status and continues other explicitly selected pairs', function (string $status): void {
    $ctx = makeMerchantActor();
    $company = $ctx['company'];
    $a = Customer::factory()->for($company, 'company')->create(['phone' => '+968 9000 0001']);
    $b = Customer::factory()->for($company, 'company')->create(['phone' => '90000001']);
    $c = Customer::factory()->for($company, 'company')->create(['phone' => '+968 9000 0002']);
    $d = Customer::factory()->for($company, 'company')->create(['phone' => '90000002']);
    $order = Order::factory()->for($company, 'company')->for($ctx['branch'], 'branch')->create(['customer_id' => $b->id, 'status' => $status]);
    if ($status === 'open') {
        DB::table('pos_order_discounts')->insert(['company_id' => $company->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $order->id, 'name_snapshot' => 'Pending points', 'amount_type_snapshot' => 'table_loyalty_redeem', 'amount' => '0.500']);
    }
    expect(Artisan::call('customers:merge-duplicates', ['--company' => $company->id, '--actor' => $ctx['user']->id, '--apply' => true, '--only' => $a->id.':'.$b->id.','.$c->id.':'.$d->id]))->toBe(1);
    expect(Artisan::output())->toContain('Merge blocked by order '.$order->id);
    expect($b->fresh()->trashed())->toBeFalse()->and($b->fresh()->merged_into_customer_id)->toBeNull();
    expect($d->fresh()->merged_into_customer_id)->toBe($c->id);
    expect($order->fresh()->customer_id)->toBe($b->id);
})->with(['open', 'held', 'kitchen', 'awaiting_payment', 'pending_verification']);

it('refuses an unauthorized actor without creating users or changing customers and clears context', function (): void {
    $ctx = makeMerchantActor();
    $a = Customer::factory()->for($ctx['company'], 'company')->create(['phone' => '+968 9000 0001']);
    $b = Customer::factory()->for($ctx['company'], 'company')->create(['phone' => '90000001']);
    $before = DB::table('pos_customers')->orderBy('id')->get()->toJson();
    $users = DB::table('pos_users')->count();
    expect(Artisan::call('customers:merge-duplicates', ['--company' => $ctx['company']->id, '--actor' => 999999, '--apply' => true, '--only' => $a->id.':'.$b->id]))->toBe(1);
    expect(Artisan::output())->toContain('customers.manage');
    expect(DB::table('pos_users')->count())->toBe($users);
    expect(DB::table('pos_customers')->orderBy('id')->get()->toJson())->toBe($before);
    expect(app(MerchantTenantContext::class)->id())->toBeNull();
    expect(getPermissionsTeamId())->toBeNull();
});

it('rolls back a merge when an unscoped ledger reference would be deleted by account cascade', function (): void {
    $ctx = makeMerchantActor();
    $company = $ctx['company'];
    $other = Company::factory()->create();
    $a = Customer::factory()->for($company, 'company')->create(['phone' => '+968 9000 0001']);
    $b = Customer::factory()->for($company, 'company')->create(['phone' => '90000001']);
    $rule = LoyaltyRule::factory()->for($company, 'company')->create();
    $aa = LoyaltyAccount::factory()->for($company, 'company')->create(['customer_id' => $a->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 200]);
    $ba = LoyaltyAccount::factory()->for($company, 'company')->create(['customer_id' => $b->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 100]);
    $txn = LoyaltyTransaction::factory()->for($company, 'company')->create(['loyalty_account_id' => $ba->id]);
    // Test-only corrupt tenant reference: the scoped move cannot see it, but the safety query must.
    DB::table('pos_loyalty_transactions')->where('id', $txn->id)->update(['company_id' => $other->id]);
    expect(Artisan::call('customers:merge-duplicates', ['--company' => $company->id, '--actor' => $ctx['user']->id, '--apply' => true, '--only' => $a->id.':'.$b->id]))->toBe(1);
    expect(Artisan::output())->toContain('still has transactions');
    expect(DB::table('pos_loyalty_transactions')->where('id', $txn->id)->value('loyalty_account_id'))->toBe($ba->id);
    expect(DB::table('pos_loyalty_accounts')->where('id', $aa->id)->value('point_balance'))->toBe(200);
    expect(DB::table('pos_loyalty_accounts')->where('id', $ba->id)->exists())->toBeTrue();
    expect(DB::table('pos_customers')->where('id', $b->id)->value('deleted_at'))->toBeNull();
});
