<?php

declare(strict_types=1);

/**
 * LAUNCH-P5 Part B follow-up 1 (tester decisions of 2026-10-04):
 *   1. Hiring into a position whose tick list holds approvals.give also needs
 *      pos_staff.change_position (like changing someone into it); other
 *      positions need only pos_staff.create.
 *   2. The Approvals report uses the Muscat business day for its date filter
 *      and per-day grouping.
 *   3. A shift re-open adds 1 to pos_shifts.reopen_count, in the same
 *      transaction that re-opens it (devices close with
 *      "shift-close:{uuid}:{reopen_count}").
 * Before: hiring a manager needed only pos_staff.create; the report used UTC
 * days; reopen_count stayed 0.
 */

use App\Enums\MerchantRole;
use App\Support\PositionPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

afterEach(function (): void {
    Carbon::setTestNow();
});

it('lets pos_staff.create alone hire into a position that cannot approve, but not into one that can', function (): void {
    $ctx = p5ActorWith(['pos_staff.view', 'pos_staff.create']);
    config(['pos.approver_kdf_iterations' => 1000]);

    $this->postJson('/api/pos-staff', ['name' => 'Huda', 'branch_id' => $ctx['branch']->id, 'position' => 'cashier'])->assertCreated();
    $this->postJson('/api/pos-staff', ['name' => 'Salim', 'branch_id' => $ctx['branch']->id, 'position' => 'manager'])->assertForbidden();
    expect(DB::table('pos_staff')->where('name', 'Salim')->exists())->toBeFalse();

    // The list tells the form which positions can approve.
    expect($this->getJson('/api/pos-staff')->assertOk()->json('meta.approver_positions'))->toBe(['manager']);
});

it('follows the company tick list: a position given approvals.give needs pos_staff.change_position to hire into', function (): void {
    $ctx = p5ActorWith(['pos_staff.view', 'pos_staff.create']);
    config(['pos.approver_kdf_iterations' => 1000]);
    $matrix = PositionPermissions::defaults();
    $matrix['supervisor']['actions']['approvals.give'] = true;
    DB::table('pos_company_settings')->insert([
        'company_id' => $ctx['company']->id, 'key' => 'position_permissions', 'value' => json_encode($matrix),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->postJson('/api/pos-staff', ['name' => 'Aisha', 'branch_id' => $ctx['branch']->id, 'position' => 'supervisor'])->assertForbidden();
    expect($this->getJson('/api/pos-staff')->json('meta.approver_positions'))->toBe(['supervisor', 'manager']);

    // With pos_staff.change_position it is allowed.
    $ok = p5ActorWith(['pos_staff.view', 'pos_staff.create', 'pos_staff.change_position']);
    $this->postJson('/api/pos-staff', ['name' => 'Salim', 'branch_id' => $ok['branch']->id, 'position' => 'manager'])->assertCreated();

    // And the default Manager role (holds both) still hires managers.
    $manager = makeMerchantActor(MerchantRole::Manager->value);
    $this->postJson('/api/pos-staff', ['name' => 'Nasser', 'branch_id' => $manager['branch']->id, 'position' => 'manager'])->assertCreated();
});

it('filters and groups the Approvals report by Muscat business day', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    // 21:30 UTC on 4 Oct = 01:30 on 5 Oct in Muscat.
    $lateNight = p5Approval($ctx['branch'], 'comp', 'verified', ['approved_at' => '2026-10-04 21:30:00']);
    // 19:00 UTC on 4 Oct = 23:00 on 4 Oct in Muscat.
    $evening = p5Approval($ctx['branch'], 'comp', 'failed', ['approved_at' => '2026-10-04 19:00:00']);
    // A missing row with no device time, recorded 20:15 UTC on 4 Oct = 00:15 on 5 Oct in Muscat.
    $missing = p5Approval($ctx['branch'], 'comp', 'missing', ['approved_at' => null, 'created_at' => '2026-10-04 20:15:00']);

    $oct5 = $this->getJson('/api/reports/approvals?date_from=2026-10-05&date_to=2026-10-05')->assertOk()->json('data');
    expect(collect($oct5['rows'])->pluck('id')->sort()->values()->all())->toBe(collect([$lateNight, $missing])->sort()->values()->all())
        ->and($oct5['window'])->toMatchArray(['from' => '2026-10-05', 'to' => '2026-10-05', 'timezone' => 'Asia/Muscat']);
    $row = collect($oct5['rows'])->firstWhere('id', $lateNight);
    expect($row)->toMatchArray(['day' => '2026-10-05', 'approved_local' => '2026-10-05 01:30']);

    $oct4 = $this->getJson('/api/reports/approvals?date_from=2026-10-04&date_to=2026-10-04')->assertOk()->json('data');
    expect(collect($oct4['rows'])->pluck('id')->all())->toBe([$evening]);

    $both = $this->getJson('/api/reports/approvals?date_from=2026-10-04&date_to=2026-10-05')->assertOk()->json('data');
    expect($both['by_day'])->toBe([
        ['day' => '2026-10-05', 'total' => 2, 'problems' => 1],
        ['day' => '2026-10-04', 'total' => 1, 'problems' => 1],
    ]);
});

it('adds 1 to reopen_count on each re-open, with the re-open', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-04 13:00:00'));
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $uuid = (string) Str::uuid();
    DB::table('pos_shifts')->insert([
        'uuid' => $uuid, 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'status' => 'closed',
        'opened_at' => '2026-10-04 04:00:00', 'closed_at' => '2026-10-04 12:00:00', 'opening_cash' => '20.000',
        'expected_cash' => '50.000', 'closing_cash' => '50.000', 'variance' => '0.000', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->postJson("/api/shifts/{$uuid}/reopen")->assertOk()->assertJsonPath('data.reopen_count', 1);
    expect((int) DB::table('pos_shifts')->where('uuid', $uuid)->value('reopen_count'))->toBe(1);

    // Closed again by a device the same day, then re-opened again: 2.
    DB::table('pos_shifts')->where('uuid', $uuid)->update(['status' => 'closed', 'closed_at' => '2026-10-04 12:45:00']);
    $this->postJson("/api/shifts/{$uuid}/reopen")->assertOk()->assertJsonPath('data.reopen_count', 2);
    expect((int) DB::table('pos_shifts')->where('uuid', $uuid)->value('reopen_count'))->toBe(2);

    $audit = DB::table('pos_audit_logs')->where('event', 'shift.reopened')->orderBy('id')->get();
    expect(json_decode($audit[1]->old_values, true)['reopen_count'])->toBe(1)
        ->and(json_decode($audit[1]->new_values, true)['reopen_count'])->toBe(2);

    // A refused re-open (already open) leaves the count alone.
    $this->postJson("/api/shifts/{$uuid}/reopen")->assertUnprocessable();
    expect((int) DB::table('pos_shifts')->where('uuid', $uuid)->value('reopen_count'))->toBe(2);
});
