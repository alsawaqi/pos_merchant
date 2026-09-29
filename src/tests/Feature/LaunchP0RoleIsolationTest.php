<?php

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('W6 rejects every manager path to creating or modifying a super admin without mutation', function () {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $super = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant']);
    $super->assignRole(MerchantRole::SuperAdmin->value);
    $viewer = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant']);
    $viewer->assignRole(MerchantRole::Viewer->value);
    $before = DB::table('pos_users')->orderBy('id')->get()->toJson();
    $roles = DB::table('pos_model_has_roles')->get()->toJson();
    $this->postJson('/api/portal-users', ['name' => 'Escalated', 'email' => 'escalated@example.test', 'role' => MerchantRole::SuperAdmin->value])->assertForbidden();
    $this->patchJson("/api/portal-users/{$viewer->id}", ['role' => MerchantRole::SuperAdmin->value])->assertForbidden();
    $this->patchJson("/api/portal-users/{$viewer->id}/roles", ['roles' => [MerchantRole::SuperAdmin->value]])->assertForbidden();
    $this->patchJson("/api/portal-users/{$super->id}", ['name' => 'Changed'])->assertForbidden();
    $this->patchJson("/api/portal-users/{$super->id}", ['role' => MerchantRole::Viewer->value])->assertForbidden();
    foreach (['reset-password', 'suspend', 'reactivate'] as $operation) {
        $this->postJson("/api/portal-users/{$super->id}/{$operation}")->assertForbidden();
    }
    expect(DB::table('pos_users')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('pos_model_has_roles')->get()->toJson())->toBe($roles);
});

it('W6 refuses peers and users outside the manager branch scope', function () {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->update(['branch_scope_json' => [$ctx['branch']->id]]);
    $other = Branch::factory()->create(['company_id' => $ctx['company']->id]);
    foreach ([[MerchantRole::Manager->value, [$ctx['branch']->id]], [MerchantRole::Viewer->value, [$other->id]]] as [$role, $scope]) {
        $target = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant', 'branch_scope_json' => $scope]);
        $target->assignRole($role);
        $hash = $target->password;
        $this->patchJson("/api/portal-users/{$target->id}", ['name' => 'No'])->assertForbidden();
        $this->postJson("/api/portal-users/{$target->id}/reset-password")->assertForbidden();
        expect($target->fresh()->password)->toBe($hash)->and($target->fresh()->name)->not->toBe('No');
    }
});

it('W6 resets revoke prior sessions and enforce password change on the server', function () {
    $ctx = makeMerchantActor();
    $target = User::factory()->create(['company_id' => $ctx['company']->id, 'user_type' => 'merchant', 'remember_token' => 'old-remember']);
    $target->assignRole(MerchantRole::Viewer->value);
    $version = (int) $target->auth_version;
    $this->postJson("/api/portal-users/{$target->id}/reset-password")->assertOk();
    expect($target->fresh()->must_change_password)->toBeTrue()->and($target->fresh()->remember_token)->toBeNull()
        ->and((int) $target->fresh()->auth_version)->not->toBe($version);
    $this->actingAs($target->fresh())->withSession(['pos.auth_version' => $version])->getJson('/api/portal-users')->assertUnauthorized();
    $this->actingAs($target->fresh())->withSession(['pos.auth_version' => (int) $target->fresh()->auth_version])
        ->getJson('/api/portal-users')->assertForbidden()->assertJsonPath('code', 'password_change_required');
});
