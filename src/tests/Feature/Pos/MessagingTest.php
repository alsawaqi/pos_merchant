<?php

declare(strict_types=1);

/**
 * P-G6 — messaging, both channels.
 *
 * Channel 1 (staff announcements → devices): compose targets + F5 scope
 * rules + receipts on the sent list + retraction; messages.send gating.
 * Channel 2 (portal inbox): the user/role/branch visibility matrix,
 * unread count, idempotent mark-read, scoped-sender branch rule.
 *
 * The Reverb nudge is config-gated and unset in tests (silent no-op) —
 * the publisher itself is unit-tested with Http::fake below.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\PortalMessage;
use App\Models\PosStaff;
use App\Models\StaffMessage;
use App\Models\StaffMessageRead;
use App\Models\User;
use App\Support\ReverbPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** A second merchant user in the same company, with a role. */
function teammate(array $ctx, string $role = MerchantRole::Viewer->value, ?array $branchScope = null): User
{
    $user = User::factory()->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
        'status' => 'active',
        'branch_scope_json' => $branchScope,
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $user->assignRole($role);

    return $user;
}

// =================== CHANNEL 1 — ANNOUNCEMENTS ===================

it('composes announcements for each target and lists receipts', function (): void {
    $ctx = makeMerchantActor();
    $staff = PosStaff::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create(['name' => 'Alice']);

    $this->postJson('/api/staff-messages', [
        'target_type' => 'company',
        'title' => 'All hands',
        'body' => 'Meeting at 5',
    ])->assertCreated();
    $this->postJson('/api/staff-messages', [
        'target_type' => 'branch',
        'target_branch_uuid' => $ctx['branch']->uuid,
        'body' => 'Branch only',
    ])->assertCreated();
    $res = $this->postJson('/api/staff-messages', [
        'target_type' => 'staff',
        'target_staff_uuid' => $staff->uuid,
        'body' => 'Just for Alice',
    ])->assertCreated();
    expect($res->json('data.target_staff.name'))->toBe('Alice');

    // A device-side receipt shows up on the sent list.
    $message = StaffMessage::query()->where('target_type', 'staff')->firstOrFail();
    StaffMessageRead::query()->create([
        'staff_message_id' => $message->id,
        'staff_id' => $staff->id,
        'read_at' => now(),
    ]);

    $rows = $this->getJson('/api/staff-messages')->assertOk()->json('data');
    expect($rows)->toHaveCount(3);
    $staffRow = collect($rows)->firstWhere('uuid', $message->uuid);
    expect($staffRow['reads'])->toHaveCount(1);
    expect($staffRow['reads'][0]['staff_name'])->toBe('Alice');
});

it('applies the F5 scope to announcement targets', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $staffB = PosStaff::factory()->for($ctx['company'], 'company')->for($branchB, 'branch')->create();
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();

    // Out-of-scope branch, out-of-scope staff, and company-wide all 403.
    $this->postJson('/api/staff-messages', [
        'target_type' => 'branch',
        'target_branch_uuid' => $branchB->uuid,
        'body' => 'X',
    ])->assertForbidden();
    $this->postJson('/api/staff-messages', [
        'target_type' => 'staff',
        'target_staff_uuid' => $staffB->uuid,
        'body' => 'X',
    ])->assertForbidden();
    $this->postJson('/api/staff-messages', [
        'target_type' => 'company',
        'body' => 'X',
    ])->assertForbidden();

    // Own branch works.
    $this->postJson('/api/staff-messages', [
        'target_type' => 'branch',
        'target_branch_uuid' => $ctx['branch']->uuid,
        'body' => 'Mine',
    ])->assertCreated();
});

it('retracts an announcement (soft delete) and forbids the unpermissioned', function (): void {
    $ctx = makeMerchantActor();
    $this->postJson('/api/staff-messages', ['target_type' => 'company', 'body' => 'Oops'])->assertCreated();
    $message = StaffMessage::query()->firstOrFail();

    $this->deleteJson("/api/staff-messages/{$message->uuid}")->assertStatus(204);
    expect(StaffMessage::query()->count())->toBe(0);
    expect(StaffMessage::withTrashed()->count())->toBe(1);

    // A Viewer holds no messages.send: every channel-1 endpoint 403s.
    makeMerchantActor(MerchantRole::Viewer->value);
    $this->getJson('/api/staff-messages')->assertForbidden();
    $this->postJson('/api/staff-messages', ['target_type' => 'company', 'body' => 'X'])->assertForbidden();
});

it('shrinks the announcements list to a scoped sender\'s branches', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $staffB = PosStaff::factory()->for($ctx['company'], 'company')->for($branchB, 'branch')->create();

    // Unrestricted: one of each audience.
    $this->postJson('/api/staff-messages', ['target_type' => 'company', 'title' => 'company-wide', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/staff-messages', ['target_type' => 'branch', 'target_branch_uuid' => $ctx['branch']->uuid, 'title' => 'branchA', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/staff-messages', ['target_type' => 'branch', 'target_branch_uuid' => $branchB->uuid, 'title' => 'branchB', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/staff-messages', ['target_type' => 'staff', 'target_staff_uuid' => $staffB->uuid, 'title' => 'staffB', 'body' => 'b'])->assertCreated();

    // A manager scoped to branch A sees only branch A's audience — not the
    // company-wide rows, not branch B's, not branch-B staff targets.
    $scoped = teammate($ctx, MerchantRole::Manager->value, [$ctx['branch']->id]);
    $this->actingAs($scoped);
    $titles = collect($this->getJson('/api/staff-messages')->assertOk()->json('data'))->pluck('title');
    expect($titles)->toContain('branchA')
        ->not->toContain('company-wide')
        ->not->toContain('branchB')
        ->not->toContain('staffB');
});

it('forbids a scoped sender from retracting out-of-scope announcements', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $this->postJson('/api/staff-messages', ['target_type' => 'company', 'title' => 'company-wide', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/staff-messages', ['target_type' => 'branch', 'target_branch_uuid' => $branchB->uuid, 'title' => 'branchB', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/staff-messages', ['target_type' => 'branch', 'target_branch_uuid' => $ctx['branch']->uuid, 'title' => 'branchA', 'body' => 'b'])->assertCreated();
    $companyWide = StaffMessage::query()->where('target_type', 'company')->firstOrFail();
    $forBranchB = StaffMessage::query()->where('target_branch_id', $branchB->id)->firstOrFail();
    $forBranchA = StaffMessage::query()->where('target_branch_id', $ctx['branch']->id)->firstOrFail();

    $scoped = teammate($ctx, MerchantRole::Manager->value, [$ctx['branch']->id]);
    $this->actingAs($scoped);
    $this->deleteJson("/api/staff-messages/{$companyWide->uuid}")->assertForbidden();
    $this->deleteJson("/api/staff-messages/{$forBranchB->uuid}")->assertForbidden();
    // In-scope retraction still works.
    $this->deleteJson("/api/staff-messages/{$forBranchA->uuid}")->assertStatus(204);
});

// =================== CHANNEL 2 — PORTAL INBOX ===================

it('resolves inbox visibility by user, role and branch scope', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $viewer = teammate($ctx, MerchantRole::Viewer->value, [$ctx['branch']->id]);

    // 1) direct to the viewer; 2) to the Viewer role group; 3) to branch A
    // (in the viewer's scope); 4) to branch B (out of scope); 5) to the
    // Manager role group (not the viewer's).
    $this->postJson('/api/messages', ['target_type' => 'user', 'target_user_id' => $viewer->id, 'subject' => 'direct', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/messages', ['target_type' => 'role', 'target_role' => MerchantRole::Viewer->value, 'subject' => 'role', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/messages', ['target_type' => 'branch', 'target_branch_uuid' => $ctx['branch']->uuid, 'subject' => 'branchA', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/messages', ['target_type' => 'branch', 'target_branch_uuid' => $branchB->uuid, 'subject' => 'branchB', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/messages', ['target_type' => 'role', 'target_role' => MerchantRole::Manager->value, 'subject' => 'managers', 'body' => 'b'])->assertCreated();

    // The sender saw 5 in Sent.
    expect($this->getJson('/api/messages/sent')->assertOk()->json('data'))->toHaveCount(5);

    // The scoped viewer sees: direct + their role + branch A. Not branch B,
    // not the managers group.
    $this->actingAs($viewer);
    $subjects = collect($this->getJson('/api/messages/inbox')->assertOk()->json('data'))->pluck('subject');
    expect($subjects)->toContain('direct')->toContain('role')->toContain('branchA')
        ->not->toContain('branchB')->not->toContain('managers');

    expect($this->getJson('/api/messages/unread-count')->assertOk()->json('data.unread'))->toBe(3);
});

it('keeps a sender\'s own role/branch sends out of their inbox and badge', function (): void {
    $ctx = makeMerchantActor(); // SuperAdmin: unrestricted scope + the role group below
    $this->postJson('/api/messages', ['target_type' => 'branch', 'target_branch_uuid' => $ctx['branch']->uuid, 'subject' => 'to my branch', 'body' => 'b'])->assertCreated();
    $this->postJson('/api/messages', ['target_type' => 'role', 'target_role' => MerchantRole::SuperAdmin->value, 'subject' => 'to my own role', 'body' => 'b'])->assertCreated();

    // Their copy lives in Sent — not back in their inbox as unread.
    expect($this->getJson('/api/messages/sent')->assertOk()->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/messages/inbox')->assertOk()->json('data'))->toHaveCount(0);
    expect($this->getJson('/api/messages/unread-count')->assertOk()->json('data.unread'))->toBe(0);

    // Another SuperAdmin in the company still receives the role send.
    $peer = teammate($ctx, MerchantRole::SuperAdmin->value);
    $this->actingAs($peer);
    $subjects = collect($this->getJson('/api/messages/inbox')->assertOk()->json('data'))->pluck('subject');
    expect($subjects)->toContain('to my own role')->toContain('to my branch');
});

it('lists in-company recipients (active users + team roles) for the pickers', function (): void {
    $ctx = makeMerchantActor();
    $viewer = teammate($ctx);
    $suspended = teammate($ctx);
    $suspended->forceFill(['status' => 'suspended'])->save();
    // A different tenant's user must never appear.
    $foreignCtx = makeMerchantActor();
    $foreign = $foreignCtx['user'];

    $this->actingAs($ctx['user']);
    $data = $this->getJson('/api/messages/recipients')->assertOk()->json('data');

    $userIds = collect($data['users'])->pluck('id');
    expect($userIds)->toContain($viewer->id)
        ->not->toContain($suspended->id)
        ->not->toContain($foreign->id);
    expect($data['roles'])->toContain(MerchantRole::Viewer->value);
});

it('marks inbox messages read idempotently and guards non-recipients', function (): void {
    $ctx = makeMerchantActor();
    $viewer = teammate($ctx);
    $other = teammate($ctx, MerchantRole::CashierSupervisor->value);

    $this->postJson('/api/messages', ['target_type' => 'user', 'target_user_id' => $viewer->id, 'body' => 'hi'])->assertCreated();
    $message = PortalMessage::query()->firstOrFail();

    $this->actingAs($viewer);
    $this->postJson("/api/messages/{$message->uuid}/read")->assertOk();
    $this->postJson("/api/messages/{$message->uuid}/read")->assertOk();
    expect($message->reads()->count())->toBe(1);
    expect($this->getJson('/api/messages/unread-count')->assertOk()->json('data.unread'))->toBe(0);

    // A non-recipient teammate can neither see nor mark it.
    $this->actingAs($other);
    expect($this->getJson('/api/messages/inbox')->assertOk()->json('data'))->toHaveCount(0);
    $this->postJson("/api/messages/{$message->uuid}/read")->assertNotFound();
});

it('rejects bogus targets and out-of-scope branch sends', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();

    $this->postJson('/api/messages', ['target_type' => 'user', 'target_user_id' => 99999, 'body' => 'b'])->assertStatus(422);
    $this->postJson('/api/messages', ['target_type' => 'role', 'target_role' => 'no_such_role', 'body' => 'b'])->assertStatus(422);
    $this->postJson('/api/messages', ['target_type' => 'branch', 'target_branch_uuid' => $branchB->uuid, 'body' => 'b'])->assertForbidden();
});

it('keeps tenants apart in both channels', function (): void {
    $ctx = makeMerchantActor();
    $this->postJson('/api/staff-messages', ['target_type' => 'company', 'body' => 'ours'])->assertCreated();
    $this->postJson('/api/messages', ['target_type' => 'role', 'target_role' => MerchantRole::SuperAdmin->value, 'subject' => 'ours', 'body' => 'b'])->assertCreated();
    $ourAnnouncement = StaffMessage::query()->firstOrFail();

    // A fresh company sees none of it.
    makeMerchantActor();
    expect($this->getJson('/api/staff-messages')->assertOk()->json('data'))->toHaveCount(0);
    expect($this->getJson('/api/messages/inbox')->assertOk()->json('data'))->toHaveCount(0);
    $this->deleteJson("/api/staff-messages/{$ourAnnouncement->uuid}")->assertNotFound();
});

// =================== THE REVERB NUDGE ===================

it('publishes signed pusher events when configured and no-ops when not', function (): void {
    Http::fake();

    // Unconfigured: silent no-op, nothing sent.
    app(ReverbPublisher::class)->publishToBranches([1], 'message.created', ['x' => 1]);
    Http::assertNothingSent();

    config()->set('services.reverb', [
        'app_id' => 'pos_api', 'key' => 'k', 'secret' => 's',
        'host' => 'reverb', 'port' => 8080, 'scheme' => 'http',
    ]);
    app(ReverbPublisher::class)->publishToBranches([1, 2, 2], 'message.created', ['message_id' => 7]);

    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        return str_starts_with($request->url(), 'http://reverb:8080/apps/pos_api/events?')
            && str_contains($request->url(), 'auth_signature=')
            && $body['name'] === 'message.created'
            && $body['channels'] === ['private-branch.1', 'private-branch.2'];
    });
});
