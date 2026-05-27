<?php

declare(strict_types=1);

use App\Support\MerchantTenantContext;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind every test under tests/Feature to TestCase, which gives them the
| Laravel application + HTTP testing helpers.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Per-test reset
|--------------------------------------------------------------------------
|
| The merchant SPA's tenant context + spatie's permission team_id are
| both request-scoped globals. Between tests we wipe them so a stale
| value from test A can't silently scope the queries in test B. This
| mirrors what SetMerchantTenantContext middleware does at runtime
| before any controller runs.
|
| Tests that exercise an authenticated flow should call:
|
|   app(MerchantTenantContext::class)->set($company->id);
|   app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
|
| early in their body — there is no equivalent of pos_admin's
| PLATFORM_TEAM_ID singleton here because every merchant test runs
| under a different company.
|
*/

uses()
    ->beforeEach(function (): void {
        app(MerchantTenantContext::class)->set(null);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared fixture helpers
|--------------------------------------------------------------------------
|
| Pest auto-loads this file before any test runs, so functions declared
| here are globally callable from every test body. Keeping them here
| (rather than scattered in test files) prevents the "where is this
| helper defined?" hunt as the suite grows.
|
*/

/**
 * Build a merchant tenant ready for authenticated HTTP requests:
 *   - one Company
 *   - one Branch under that company
 *   - one merchant User assigned the requested role under the
 *     company's spatie team scope
 *   - MerchantTenantContext pinned so middleware-free Action calls
 *     also see the tenant
 *   - actingAs() called on the test instance
 *
 * @return array{company: \App\Models\Company, branch: \App\Models\Branch, user: \App\Models\User}
 */
function makeMerchantActor(string $role = \App\Enums\MerchantRole::SuperAdmin->value): array
{
    $company = \App\Models\Company::factory()->create();
    $branch = \App\Models\Branch::factory()->for($company, 'company')->create();

    // Seed the role catalogue under this company's team scope so
    // the role we're about to assign exists with the right
    // permission set.
    app(\App\Actions\Admin\SeedMerchantRolesAction::class)->handle($company->id);

    /** @var \App\Models\User $user */
    $user = \App\Models\User::factory()->create([
        'company_id' => $company->id,
        'user_type' => 'merchant',
        'status' => 'active',
    ]);

    // Spatie's HasRoles trait reads team_id from the registrar at
    // assign time — pin it before calling assignRole().
    $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $user->assignRole($role);

    // Pin the merchant tenant context as SetMerchantTenantContext
    // middleware would on every authed request. The actingAs()
    // call below makes Sanctum/web guard return this user;
    // middleware then resolves $request->user()->company_id and
    // sets the context. We set it eagerly so Action-layer
    // operations that don't go through the HTTP stack also work.
    app(\App\Support\MerchantTenantContext::class)->set($company->id);

    test()->actingAs($user);

    return ['company' => $company, 'branch' => $branch, 'user' => $user];
}
