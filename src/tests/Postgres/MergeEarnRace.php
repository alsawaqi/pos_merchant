<?php

declare(strict_types=1);

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Actions\Pos\Loyalty\WriteLoyaltyTransactionAction;
use App\Enums\LoyaltyTransactionType;
use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): never {
    fwrite(STDERR, $e->__toString().PHP_EOL);
    exit(1);
});
if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'qr_fix4')) {
    throw new RuntimeException('Disposable qr_fix4 PostgreSQL required');
}
if (($argv[1] ?? '') === 'worker') {
    DB::statement("SET application_name TO 'qr_fix4_merge_worker'");
    $code = Artisan::call('customers:merge-duplicates', ['--company' => (int) $argv[2], '--actor' => (int) $argv[3], '--apply' => true, '--only' => $argv[4]]);
    echo Artisan::output();
    exit($code);
}
$company = Company::factory()->create(['name' => 'FIX4 MERGE RACE']);
$branch = Branch::factory()->for($company, 'company')->create();
app(SeedMerchantRolesAction::class)->handle($company->id);
$actor = User::factory()->create(['company_id' => $company->id, 'user_type' => 'merchant', 'status' => 'active']);
setPermissionsTeamId($company->id);
$actor->assignRole(MerchantRole::SuperAdmin->value);
app(MerchantTenantContext::class)->set($company->id);
$a = Customer::factory()->for($company, 'company')->create(['phone' => '+968 9000 0003']);
$b = Customer::factory()->for($company, 'company')->create(['phone' => '90000003']);
$rule = LoyaltyRule::factory()->for($company, 'company')->create(['type' => 'spend_based', 'config_json' => ['points_per_omr' => 0, 'redemption_points' => 100, 'redemption_value' => '0.500'], 'status' => 'active']);
$aa = LoyaltyAccount::factory()->for($company, 'company')->create(['customer_id' => $a->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 200, 'stamp_count' => 10]);
$ba = LoyaltyAccount::factory()->for($company, 'company')->create(['customer_id' => $b->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 100, 'stamp_count' => 5]);
DB::table('pos_customer_vehicle_plates')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'customer_id' => $b->id, 'plate_number' => 'MERGED-RACE']);
$before = DB::table('pos_loyalty_transactions')->count();
DB::beginTransaction();
LoyaltyAccount::whereKey($ba->id)->lockForUpdate()->firstOrFail();
$process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $company->id, (string) $actor->id, $a->id.':'.$b->id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fclose($pipes[0]);
try {
    $deadline = microtime(true) + 20;
    while (! DB::table('pg_stat_activity')->where('application_name', 'qr_fix4_merge_worker')->where('wait_event_type', 'Lock')->exists()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Merge never reached the real account-row lock');
        }
        DB::select('SELECT pg_stat_clear_snapshot()');
        usleep(1000);
    }
    app(WriteLoyaltyTransactionAction::class)->handle($ba, LoyaltyTransactionType::Earn, 10, 1, $actor);
    DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    throw $e;
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
echo $out;
if ($exit !== 0) {
    throw new RuntimeException($err);
}
$after = $aa->fresh();
if ($after->point_balance !== 310 || $after->stamp_count !== 16 || DB::table('pos_loyalty_transactions')->count() !== $before + 1) {
    throw new RuntimeException('Concurrent earn must be included exactly once in the locked merge');
}
if (DB::table('pos_loyalty_transactions')->where('loyalty_account_id', $ba->id)->exists()) {
    throw new RuntimeException('Orphaned source transactions');
}
echo 'PASS MERGE_EARN '.json_encode(['company' => $company->id, 'branch' => $branch->id, 'survivor' => $a->id, 'source' => $b->id, 'rule' => $rule->id, 'points' => $after->point_balance, 'stamps' => $after->stamp_count, 'new_transactions' => 1]).PHP_EOL;
