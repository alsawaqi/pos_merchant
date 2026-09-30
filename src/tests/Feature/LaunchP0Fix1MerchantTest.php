<?php

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function fix1Deputy(): array
{
    $ctx = makeMerchantActor();
    $deputy = Role::create(['team_id' => $ctx['company']->id, 'guard_name' => 'web', 'name' => 'Deputy', 'is_system' => false]);
    $deputy->syncPermissions(['roles.manage', 'roles.view', 'portal_users.view', 'portal_users.update']);
    $ctx['user']->syncRoles([$deputy]);
    $ctx['user']->unsetRelation('roles')->unsetRelation('permissions');

    return $ctx;
}

it('fix1 B10 refuses Deputy role creation escalation without writes', function (array $permissions) {
    $ctx = fix1Deputy();
    $before = DB::table('pos_roles')->get()->toJson();
    $this->postJson('/api/roles', ['name' => 'Escalated', 'permissions' => $permissions])->assertForbidden()
        ->assertJsonStructure(['message']);
    expect(DB::table('pos_roles')->get()->toJson())->toBe($before);
})->with([[['roles.manage']], [['catalogue.manage']]]);

it('fix1 B10 refuses Deputy permission updates and editing privileged role metadata', function (string $path) {
    $ctx = fix1Deputy();
    $role = Role::create(['team_id' => $ctx['company']->id, 'guard_name' => 'web', 'name' => 'Target', 'is_system' => false]);
    $role->syncPermissions($path === 'metadata' ? ['roles.manage'] : ['roles.view']);
    $target = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant']);
    $target->assignRole($role);
    $before = DB::table('pos_role_has_permissions')->get()->toJson();
    $payload = $path === 'metadata' ? ['description' => 'Changed'] : ['permissions' => [$path]];
    $this->patchJson('/api/roles/'.$role->id, $payload)->assertForbidden()->assertJsonStructure(['message']);
    expect($role->fresh()->description)->toBeNull()
        ->and(DB::table('pos_role_has_permissions')->get()->toJson())->toBe($before);
})->with(['roles.manage', 'catalogue.manage', 'metadata']);

it('fix1 B10 assignRoles preserves a useful 403 when Deputy attempts to grant Super Admin', function () {
    $ctx = fix1Deputy();
    $target = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant']);
    $target->assignRole(MerchantRole::Viewer->value);
    $before = DB::table('pos_model_has_roles')->get()->toJson();
    $res = $this->patchJson("/api/portal-users/{$target->id}/roles", ['roles' => [MerchantRole::SuperAdmin->value]])->assertForbidden();
    expect($res->json('message'))->toBeString()->not->toBeEmpty();
    expect(DB::table('pos_model_has_roles')->get()->toJson())->toBe($before);
});

it('fix1 B11 authenticates signed-out bound routes before tenant binding', function (string $url) {
    $this->getJson($url.Str::uuid())->assertUnauthorized();
    $this->get($url.Str::uuid())->assertRedirect('/login');
})->with(['/api/products/', '/print/table-cards/']);

it('fix1 checks suspended company after password and rate limit', function () {
    $company = Company::factory()->create(['status' => 'suspended']);
    $user = User::factory()->create(['company_id' => $company->id, 'user_type' => 'merchant', 'password' => 'known-password']);
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertUnprocessable()->assertJsonMissing(['message' => 'Account suspended.']);
    $key = Str::transliterate(Str::lower($user->email).'|127.0.0.1');
    expect(RateLimiter::attempts($key))->toBe(1);
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'known-password'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Account suspended.');
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit($key, 60);
    }
    $res = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'known-password'])->assertUnprocessable();
    expect($res->json('errors.email.0'))->not->toBe('Account suspended.');
    $this->assertGuest();
});

it('fix1 recipient picker respects the users branch scope', function () {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->update(['branch_scope_json' => [$ctx['branch']->id]]);
    $other = Branch::factory()->for($ctx['company'])->create();
    $visible = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant',
        'branch_scope_json' => [$ctx['branch']->id]]);
    $hidden = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant',
        'branch_scope_json' => [$other->id]]);
    $ids = collect($this->getJson('/api/messages/recipients')->assertOk()->json('data.users'))->pluck('id')->all();
    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});

it('fix1 historical report filters accept deleted company branches and refuse foreign branches', function () {
    $ctx = makeMerchantActor();
    $ctx['branch']->delete();
    $query = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'branch_ids' => [$ctx['branch']->id]];
    $this->getJson('/api/reports/sales?'.http_build_query($query))->assertOk();
    $other = Branch::factory()->create();
    $other->delete();
    $query['branch_ids'] = [$other->id];
    $this->getJson('/api/reports/sales?'.http_build_query($query))->assertUnprocessable();
});
