<?php

declare(strict_types=1);

/**
 * LAUNCH-P5 B6 — branch settings: the shift-end reminder time
 * (pos_branch_settings key shift_end_reminder_at, "HH:MM" Muscat time or
 * null = off; blank in the form = off). Read with branches.view, change with
 * branches.update inside the user's branch scope; audited. Before: no
 * setting.
 */

use App\Enums\MerchantRole;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** The stored JSON value of the branch's reminder row ('absent' when there is no row). */
function p5Reminder(int $branchId): mixed
{
    $row = DB::table('pos_branch_settings')->where('branch_id', $branchId)->where('key', 'shift_end_reminder_at')->first();

    return $row === null ? 'absent' : ($row->value === null ? null : json_decode((string) $row->value, true));
}

it('sets, changes and clears the shift-end reminder time of a branch, audited', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $url = "/api/pos/branches/{$ctx['branch']->uuid}/shift-end-reminder";

    $this->getJson($url)->assertOk()->assertJsonPath('data.shift_end_reminder_at', null);

    $this->putJson($url, ['shift_end_reminder_at' => '21:30'])->assertOk()->assertJsonPath('data.shift_end_reminder_at', '21:30');
    expect(p5Reminder($ctx['branch']->id))->toBe('21:30');
    $this->getJson($url)->assertOk()->assertJsonPath('data.shift_end_reminder_at', '21:30');

    $this->putJson($url, ['shift_end_reminder_at' => '23:00'])->assertOk();
    // Blank = off.
    $this->putJson($url, ['shift_end_reminder_at' => ''])->assertOk()->assertJsonPath('data.shift_end_reminder_at', null);
    expect(p5Reminder($ctx['branch']->id))->toBeNull();

    $audit = DB::table('pos_audit_logs')->where('event', 'settings.shift_end_reminder.branch_updated')->orderBy('id')->get();
    expect($audit)->toHaveCount(3)
        ->and(json_decode($audit[0]->new_values, true))->toBe(['shift_end_reminder_at' => '21:30'])
        ->and(json_decode($audit[2]->old_values, true))->toBe(['shift_end_reminder_at' => '23:00'])
        ->and((int) $audit[2]->branch_id)->toBe($ctx['branch']->id);

    // Saving the same value again records nothing.
    $this->putJson($url, ['shift_end_reminder_at' => null])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'settings.shift_end_reminder.branch_updated')->count())->toBe(3);
});

it('refuses a time that is not HH:MM', function (string $time): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);

    $this->putJson("/api/pos/branches/{$ctx['branch']->uuid}/shift-end-reminder", ['shift_end_reminder_at' => $time])->assertStatus(422);
    expect(p5Reminder($ctx['branch']->id))->toBe('absent');
})->with(['24:00', '9:30', '21:30:00', '21.30', 'nine']);

it('needs branches.update to change it, keeps branch-limited users to their branches, and each company to its own', function (): void {
    $viewer = makeMerchantActor(MerchantRole::Viewer->value);
    $this->getJson("/api/pos/branches/{$viewer['branch']->uuid}/shift-end-reminder")->assertOk();
    $this->putJson("/api/pos/branches/{$viewer['branch']->uuid}/shift-end-reminder", ['shift_end_reminder_at' => '21:30'])->assertForbidden();

    $owner = makeMerchantActor(MerchantRole::Manager->value);
    $b2 = p5Branch($owner['company'], 'Seeb');
    $limited = User::factory()->create(['company_id' => $owner['company']->id, 'user_type' => 'merchant', 'status' => 'active', 'branch_scope_json' => [$owner['branch']->id]]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($owner['company']->id);
    $limited->assignRole(MerchantRole::Manager->value);
    $this->actingAs($limited);
    $this->putJson("/api/pos/branches/{$b2->uuid}/shift-end-reminder", ['shift_end_reminder_at' => '21:30'])->assertForbidden();
    $this->putJson("/api/pos/branches/{$owner['branch']->uuid}/shift-end-reminder", ['shift_end_reminder_at' => '21:30'])->assertOk();

    $this->actingAs($viewer['user']);
    app(MerchantTenantContext::class)->set($viewer['company']->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($viewer['company']->id);
    $this->getJson("/api/pos/branches/{$owner['branch']->uuid}/shift-end-reminder")->assertNotFound();
    expect(p5Reminder($b2->id))->toBe('absent');
});
