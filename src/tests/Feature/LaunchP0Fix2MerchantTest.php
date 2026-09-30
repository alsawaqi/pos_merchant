<?php

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function fix2Deputy(): array
{
    $ctx = makeMerchantActor();
    $role = Role::create(['team_id' => $ctx['company']->id, 'guard_name' => 'web', 'name' => 'Deputy', 'is_system' => false]);
    $role->syncPermissions(['roles.manage', 'roles.view', 'portal_users.view', 'portal_users.update']);
    $ctx['user']->syncRoles([$role]);
    $ctx['user']->unsetRelation('roles')->unsetRelation('permissions');
    return $ctx;
}

it('F12 passive access probes preserve idle expiry while detecting suspension', function () {
    $ctx = makeMerchantActor();
    $start = now()->timestamp;
    $this->withSession(['pos_merchant.last_activity_at' => $start, 'pos_merchant.remembered' => false]);
    for ($minute = 1; $minute <= 29; $minute++) {
        $this->travel(1)->minutes();
        $this->getJson('/auth/access')->assertOk();
        expect(session('pos_merchant.last_activity_at'))->toBe($start);
    }
    $this->travel(2)->minutes();
    $this->getJson('/auth/access')->assertUnauthorized();
    $this->assertGuest();
});

it('F12 passive access probe sees company suspension', function () {
    $ctx = makeMerchantActor();
    $ctx['company']->forceFill(['status' => 'suspended'])->save();
    $this->getJson('/auth/access')->assertForbidden()->assertJsonPath('code', 'company_suspended');
});

it('F12 Deputy creates and deletes a strictly lower role', function () {
    $ctx = fix2Deputy();
    $id = $this->postJson('/api/roles', ['name' => 'Lower reader', 'permissions' => ['roles.view']])
        ->assertCreated()->json('data.id');
    $this->deleteJson('/api/roles/'.$id)->assertNoContent();
});

it('F12 Deputy cannot delete an unused privileged role', function () {
    $ctx = fix2Deputy();
    $role = Role::create(['team_id' => $ctx['company']->id, 'guard_name' => 'web', 'name' => 'Unused privileged', 'is_system' => false]);
    $role->syncPermissions(['roles.manage']);
    $this->deleteJson('/api/roles/'.$role->id)->assertForbidden();
    expect($role->fresh())->not->toBeNull();
});

it('F12 branch-restricted sender can reach a visible teammate but not a hidden teammate or company role', function () {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->update(['branch_scope_json' => [$ctx['branch']->id]]);
    $other = Branch::factory()->for($ctx['company'])->create();
    $visible = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant',
        'branch_scope_json' => [$ctx['branch']->id]]);
    $hidden = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant',
        'branch_scope_json' => [$other->id]]);
    $this->postJson('/api/messages', ['target_type' => 'user', 'target_user_id' => $visible->id, 'body' => 'In scope'])->assertCreated();
    $before = DB::table('pos_portal_messages')->count();
    $this->postJson('/api/messages', ['target_type' => 'user', 'target_user_id' => $hidden->id, 'body' => 'Hidden'])->assertForbidden();
    $this->postJson('/api/messages', ['target_type' => 'role', 'target_role' => MerchantRole::Viewer->value, 'body' => 'All viewers'])->assertForbidden();
    expect(DB::table('pos_portal_messages')->count())->toBe($before);
    expect($this->getJson('/api/messages/recipients')->assertOk()->json('data.roles'))->toBe([]);
});

it('F12 unrestricted sender can send a company role message', function () {
    makeMerchantActor();
    $this->postJson('/api/messages', ['target_type' => 'role', 'target_role' => MerchantRole::Viewer->value, 'body' => 'All viewers'])->assertCreated();
});
