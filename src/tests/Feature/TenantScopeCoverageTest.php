<?php

declare(strict_types=1);

use App\Models\Scopes\BelongsToCompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase 6 regression guard. Prevents a future model that adds a company_id
 * column but forgets the BelongsToCompany trait — which would silently reopen
 * the cross-tenant hole Phase 2 closed. Every model whose table carries
 * company_id MUST register the tenant global scope, unless it is on the
 * documented exemption list below (each with a reason).
 */
it('every company-owned model enforces the tenant global scope', function (): void {
    // Models that have a company_id column but are INTENTIONALLY unscoped.
    // Adding to this list is a deliberate, reviewed decision — state why.
    $exempt = [
        \App\Models\User::class,     // login resolves users by email before any tenant is known; company_id is nullable (platform admins)
        \App\Models\AuditLog::class, // append-only; writes pass an explicit company_id (incl. no-tenant paths like password reset)
        \App\Models\Device::class,   // company_id is nullable (registered-but-unassigned devices)
    ];

    $missing = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $model = new $class();
        if (! Schema::hasColumn($model->getTable(), 'company_id')) {
            continue; // not a directly company-owned table
        }
        if (in_array($class, $exempt, true)) {
            continue;
        }

        if (! array_key_exists(BelongsToCompanyScope::class, $model->getGlobalScopes())) {
            $missing[] = class_basename($class);
        }
    }

    expect($missing)->toBe(
        [],
        'Company-owned models missing the BelongsToCompany global scope (add the trait, or exempt with a reason): '.implode(', ', $missing),
    );
});
