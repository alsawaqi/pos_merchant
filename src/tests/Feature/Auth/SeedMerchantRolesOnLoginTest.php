<?php

declare(strict_types=1);

/**
 * The merchant owner is provisioned by pos_admin with an EMPTY
 * `merchant_super_admin` role (pos_admin cannot seed this app's
 * permission catalogue). SeedMerchantRolesOnLogin closes that gap: on
 * login it runs SeedMerchantRolesAction, which force-syncs the owner
 * role to the full MerchantPermission set — so a brand-new merchant is
 * never locked out of its own portal.
 */

use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('force-syncs the empty owner role to the full permission set on login', function (): void {
    $registrar = app(PermissionRegistrar::class);

    // Arrange: mimic pos_admin's CreateMerchantUserAction — a merchant
    // owner holding an EMPTY merchant_super_admin role, with NO
    // catalogue seeded under the company team scope.
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => 'merchant',
        'status' => 'active',
    ]);

    $registrar->setPermissionsTeamId($company->id);
    $role = Role::create([
        'name' => MerchantRole::SuperAdmin->value,
        'guard_name' => 'web',
        'team_id' => $company->id,
    ]);
    $user->assignRole($role);
    $registrar->forgetCachedPermissions();

    // Precondition: the owner role is genuinely empty.
    expect($role->permissions()->count())->toBe(0);

    // Act: the login event fires the seeding listener.
    event(new Login('web', $user, false));

    // Assert: SuperAdmin now holds the complete merchant catalogue.
    $registrar->forgetCachedPermissions();
    expect($role->fresh()->permissions()->count())
        ->toBe(count(MerchantPermission::values()));
});

it('does not seed roles for a non-merchant user', function (): void {
    $registrar = app(PermissionRegistrar::class);

    $company = Company::factory()->create();
    $platformUser = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => 'platform_admin',
        'status' => 'active',
    ]);

    // Act: a non-merchant login must be ignored by the listener.
    event(new Login('web', $platformUser, false));

    // Assert: no roles were seeded under this company's team scope.
    $registrar->setPermissionsTeamId($company->id);
    expect(Role::query()->where('team_id', $company->id)->count())->toBe(0);
});
