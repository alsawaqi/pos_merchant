<?php

declare(strict_types=1);

/**
 * Feature tests for the merchant-side Roles & Permissions
 * builder (App\Http\Controllers\Pos\RolesController) +
 * AssignRolesToUserAction on PortalUsersController.
 *
 * Symmetric to the platform-side test in pos_admin's
 * tests/Feature/Admin/RolesControllerTest.php — same shape,
 * same invariants, but team-scoped to the actor's company
 * instead of the platform sentinel team_id=0.
 *
 * Proves end-to-end through the HTTP stack:
 *
 *   - actingAs() + makeMerchantActor() helper gives the
 *     controller's $user->can(...) checks the right team scope
 *     to read from.
 *   - System roles (the 5 defaults seeded per-company by
 *     SeedMerchantRolesAction) can have their permission set
 *     edited but cannot be renamed or deleted.
 *   - Custom roles are fully CRUD-able by anyone with
 *     RolesManage.
 *   - Cross-tenant safety: a role from a DIFFERENT merchant's
 *     team scope is invisible (404).
 *   - SuperAdmin self-rescue: certain permissions can never be
 *     stripped from a SuperAdmin row.
 *   - AssignRolesToUser refuses an actor stripping their own
 *     SuperAdmin role + refuses cross-company targets.
 */

use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// =================== LIST ===================

it('lists every role in the actor company team scope', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->getJson('/api/roles')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain(
        MerchantRole::SuperAdmin->value,
        MerchantRole::Manager->value,
        MerchantRole::CashierSupervisor->value,
        MerchantRole::Viewer->value,
        MerchantRole::InventoryManager->value,
    );

    // Each row carries the Phase 4.8 fields.
    foreach ($response->json('data') as $row) {
        expect($row)->toHaveKeys(['id', 'name', 'description', 'is_system', 'permissions', 'user_count']);
        expect($row['is_system'])->toBeTrue(); // all seeded merchant defaults
    }
});

it('does not surface another company roles in the merchant list', function (): void {
    $ctx = makeMerchantActor();

    // A second company with its own seeded role catalog. Its
    // roles must NOT appear in the actor's listing because the
    // controller scopes by team_id = actor company id.
    $otherCompany = Company::factory()->create();
    app(\App\Actions\Admin\SeedMerchantRolesAction::class)->handle($otherCompany->id);
    // Add a custom role to the other company so the names
    // would collide if scoping leaked.
    app(PermissionRegistrar::class)->setPermissionsTeamId($otherCompany->id);
    Role::query()->create([
        'name' => 'OtherCompanyCustom',
        'guard_name' => 'web',
        'team_id' => $otherCompany->id,
        'is_system' => false,
    ]);

    // Restore the actor's scope before the request fires.
    app(MerchantTenantContext::class)->set($ctx['company']->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);

    $response = $this->getJson('/api/roles')->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->not->toContain('OtherCompanyCustom');
    // And exactly 5 default roles + zero custom = 5 rows for
    // this actor.
    expect($response->json('data'))->toHaveCount(5);
});

// =================== CATALOG ===================

it('returns the grouped merchant permission catalog with EN+AR labels', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/roles/catalog')->assertOk();
    $groups = $response->json('data');

    $groupKeys = collect($groups)->pluck('key')->all();
    expect($groupKeys)->toContain('portal_users', 'pos_staff', 'branches', 'roles');

    foreach ($groups as $group) {
        foreach ($group['permissions'] as $perm) {
            expect($perm)->toHaveKeys(['key', 'label_en', 'label_ar']);
            expect($perm['label_en'])->toBeString()->not->toBeEmpty();
            expect($perm['label_ar'])->toBeString()->not->toBeEmpty();
        }
    }
});

// =================== CREATE ===================

it('creates a custom role with the requested permissions', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/roles', [
        'name' => 'Night Manager',
        'description' => 'Custom — manages overnight shift, no role mgmt.',
        'permissions' => [
            MerchantPermission::PosStaffView->value,
            MerchantPermission::PosStaffUpdate->value,
            MerchantPermission::BranchesView->value,
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Night Manager')
        ->assertJsonPath('data.is_system', false);

    $role = Role::query()
        ->where('name', 'Night Manager')
        ->where('team_id', $ctx['company']->id)
        ->firstOrFail();
    // is_system reads as int from sqlite (spatie has no bool
    // cast on the column); the user-facing JSON does the cast,
    // verified by the response assertion above.
    expect((bool) $role->is_system)->toBeFalse();
    expect($role->permissions()->pluck('name')->sort()->values()->all())->toBe([
        MerchantPermission::BranchesView->value,
        MerchantPermission::PosStaffUpdate->value,
        MerchantPermission::PosStaffView->value,
    ]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'role.created',
        'auditable_id' => $role->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('rejects an unknown permission string on create', function (): void {
    makeMerchantActor();

    $this->postJson('/api/roles', [
        'name' => 'Bogus Test',
        'permissions' => [
            MerchantPermission::PosStaffView->value,
            'something.completely.fake',
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['permissions.1']);
});

it('rejects a duplicate role name within the actor company', function (): void {
    makeMerchantActor();

    $this->postJson('/api/roles', [
        'name' => MerchantRole::Manager->value, // collides with seeded default
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('allows the same role name to exist under two different companies', function (): void {
    // Sanity check on team scoping — Company A and Company B
    // can each have a "Night Manager" custom role without
    // colliding because the spatie UNIQUE is (team_id, name).
    $ctx = makeMerchantActor();

    // Create a Night Manager role for company A via the API.
    $this->postJson('/api/roles', ['name' => 'Night Manager'])->assertCreated();

    // Spin up an entirely separate company B + actor + create
    // the same-named role through their controller.
    $ctxB = makeMerchantActor();
    $this->postJson('/api/roles', ['name' => 'Night Manager'])->assertCreated();

    // Both rows exist in pos_roles.
    expect(Role::query()->where('name', 'Night Manager')->count())->toBe(2);
});

// =================== UPDATE ===================

it('edits a custom role name, description, and permissions', function (): void {
    $ctx = makeMerchantActor();

    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    /** @var Role $role */
    $role = Role::query()->create([
        'name' => 'Original',
        'guard_name' => 'web',
        'team_id' => $ctx['company']->id,
        'is_system' => false,
        'description' => 'old',
    ]);

    $this->patchJson("/api/roles/{$role->id}", [
        'name' => 'Renamed',
        'description' => 'new',
        'permissions' => [MerchantPermission::BranchesView->value],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.description', 'new');

    $role->refresh();
    expect($role->name)->toBe('Renamed');
    expect($role->permissions()->pluck('name')->all())->toBe([MerchantPermission::BranchesView->value]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'role.updated',
        'auditable_id' => $role->id,
    ]);
});

it('refuses to rename a system role but allows permission edits', function (): void {
    $ctx = makeMerchantActor();

    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $managerRole = Role::query()
        ->where('name', MerchantRole::Manager->value)
        ->where('team_id', $ctx['company']->id)
        ->firstOrFail();

    $this->patchJson("/api/roles/{$managerRole->id}", [
        'name' => 'Renamed Manager',
    ])->assertStatus(422);

    // Permission edit on the same row still works.
    $this->patchJson("/api/roles/{$managerRole->id}", [
        'description' => 'Tightened Manager — no portal user invites.',
        'permissions' => [MerchantPermission::PosStaffView->value],
    ])
        ->assertOk()
        ->assertJsonPath('data.description', 'Tightened Manager — no portal user invites.');
});

it('refuses to strip a locked permission from SuperAdmin', function (): void {
    $ctx = makeMerchantActor();

    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $superAdminRole = Role::query()
        ->where('name', MerchantRole::SuperAdmin->value)
        ->where('team_id', $ctx['company']->id)
        ->firstOrFail();

    // Submit the full enum MINUS roles.manage — locked perm.
    $response = $this->patchJson("/api/roles/{$superAdminRole->id}", [
        'permissions' => array_values(array_diff(
            MerchantPermission::values(),
            [MerchantPermission::RolesManage->value],
        )),
    ])->assertStatus(422);

    expect($response->json('message'))->toContain(MerchantPermission::RolesManage->value);
});

// =================== DELETE ===================

it('deletes a custom role with zero assigned users', function (): void {
    $ctx = makeMerchantActor();

    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $role = Role::query()->create([
        'name' => 'To Be Deleted',
        'guard_name' => 'web',
        'team_id' => $ctx['company']->id,
        'is_system' => false,
    ]);
    $roleId = $role->id;

    $this->deleteJson("/api/roles/{$role->id}")->assertNoContent();

    expect(Role::query()->find($roleId))->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'role.deleted',
        'auditable_id' => $roleId,
        'company_id' => $ctx['company']->id,
    ]);
});

it('refuses to delete a system role', function (): void {
    $ctx = makeMerchantActor();

    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $superAdminRole = Role::query()
        ->where('name', MerchantRole::SuperAdmin->value)
        ->where('team_id', $ctx['company']->id)
        ->firstOrFail();

    $this->deleteJson("/api/roles/{$superAdminRole->id}")
        ->assertStatus(422);

    expect(Role::query()->find($superAdminRole->id))->not->toBeNull();
});

it('refuses to delete a custom role still assigned to a user', function (): void {
    $ctx = makeMerchantActor();

    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $role = Role::query()->create([
        'name' => 'In Use',
        'guard_name' => 'web',
        'team_id' => $ctx['company']->id,
        'is_system' => false,
    ]);

    $assignee = User::factory()->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
        'status' => 'active',
    ]);
    $assignee->assignRole($role);

    $response = $this->deleteJson("/api/roles/{$role->id}")
        ->assertStatus(422);
    expect($response->json('message'))->toContain('assigned to');

    expect(Role::query()->find($role->id))->not->toBeNull();
});

// =================== CROSS-TENANT ===================

it('returns 404 when targeting a role from a different company team scope', function (): void {
    $ctx = makeMerchantActor();

    // A role belonging to a DIFFERENT company.
    $otherCompany = Company::factory()->create();
    $otherRole = Role::query()->create([
        'name' => 'OtherCompanyCustom',
        'guard_name' => 'web',
        'team_id' => $otherCompany->id,
        'is_system' => false,
    ]);

    $this->patchJson("/api/roles/{$otherRole->id}", ['description' => 'never'])
        ->assertNotFound();
    $this->deleteJson("/api/roles/{$otherRole->id}")
        ->assertNotFound();
});

// =================== PERMISSION GATES ===================

it('forbids reading the role list when the actor lacks RolesView', function (): void {
    // Bootstrap a fresh merchant tenant but assign the actor
    // to an empty custom role (no permissions at all).
    $company = Company::factory()->create();
    app(\App\Actions\Admin\SeedMerchantRolesAction::class)->handle($company->id);

    /** @var User $user */
    $user = User::factory()->create([
        'company_id' => $company->id,
        'user_type' => 'merchant',
        'status' => 'active',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $emptyRole = Role::query()->create([
        'name' => 'empty',
        'guard_name' => 'web',
        'team_id' => $company->id,
        'is_system' => false,
    ]);
    $user->assignRole($emptyRole);
    app(MerchantTenantContext::class)->set($company->id);
    $this->actingAs($user);

    $this->getJson('/api/roles')->assertForbidden();
});

it('forbids creating a role without RolesManage (Viewer can browse but not create)', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->postJson('/api/roles', ['name' => 'Sneaky'])->assertForbidden();
});

// =================== ASSIGN ROLES TO PORTAL USER ===================

it('assigns multiple roles to a portal user', function (): void {
    $ctx = makeMerchantActor();

    $target = User::factory()->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
        'status' => 'active',
    ]);

    $this->patchJson("/api/portal-users/{$target->id}/roles", [
        'roles' => [
            MerchantRole::Manager->value,
            MerchantRole::CashierSupervisor->value,
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonCount(2, 'data.roles');

    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    expect($target->fresh()->getRoleNames()->sort()->values()->all())->toBe([
        MerchantRole::CashierSupervisor->value,
        MerchantRole::Manager->value,
    ]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.roles_changed',
        'auditable_id' => $target->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('refuses an actor removing their own SuperAdmin role', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->patchJson("/api/portal-users/{$ctx['user']->id}/roles", [
        'roles' => [MerchantRole::Viewer->value],
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('Super Admin');
});

it('returns 404 when assigning roles to a portal user from another company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $foreignUser = User::factory()->create([
        'company_id' => $otherCompany->id,
        'user_type' => 'merchant',
        'status' => 'active',
    ]);

    $this->patchJson("/api/portal-users/{$foreignUser->id}/roles", [
        'roles' => [MerchantRole::Viewer->value],
    ])->assertNotFound();
});

it('forbids assigning roles without RolesManage (Manager-level cannot promote)', function (): void {
    // Manager has portal_users.update but NOT roles.manage —
    // the assign-roles endpoint is intentionally gated on
    // RolesManage so a Manager can rename teammates but
    // can't promote one to SuperAdmin.
    $ctx = makeMerchantActor(MerchantRole::Manager->value);

    $target = User::factory()->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
        'status' => 'active',
    ]);

    $this->patchJson("/api/portal-users/{$target->id}/roles", [
        'roles' => [MerchantRole::Viewer->value],
    ])->assertForbidden();
});
