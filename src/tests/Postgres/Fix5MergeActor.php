<?php

declare(strict_types=1);

/*
 * S1 P7 — standalone script (not a Pest test) for the disposable PostgreSQL t12bf4-s1-pg only.
 * Mode: actor <company_id>  -> seeds the merchant role catalogue for that company and one merchant user
 * holding the SuperAdmin role (customers.manage), prints its id. Creates nothing else.
 */

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Enums\MerchantRole;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'qr_fix4_fix5') {
    fwrite(STDERR, "refusing: not the S1 disposable database\n");
    exit(2);
}
[$self, $mode, $company] = $argv + [null, null, null];
if ($mode !== 'actor') {
    exit(2);
}
$company = (int) $company;
app(SeedMerchantRolesAction::class)->handle($company);
$user = User::factory()->create(['company_id' => $company, 'user_type' => 'merchant', 'status' => 'active']);
app(PermissionRegistrar::class)->setPermissionsTeamId($company);
$user->assignRole(MerchantRole::SuperAdmin->value);
echo 'ACTOR '.json_encode(['company' => $company, 'actor' => $user->id, 'can_manage' => $user->can('customers.manage')]).PHP_EOL;
