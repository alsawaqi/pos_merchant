<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Actions\Admin\SeedMerchantRolesAction;
use Illuminate\Support\Facades\DB;

$companies = DB::table('pos_users')
    ->where('user_type', 'merchant')
    ->whereNotNull('company_id')
    ->distinct()
    ->pluck('company_id');

$action = app(SeedMerchantRolesAction::class);
foreach ($companies as $companyId) {
    $action->handle((int) $companyId);
    echo "Reseeded roles for company {$companyId}\n";
}
echo "Done.\n";
