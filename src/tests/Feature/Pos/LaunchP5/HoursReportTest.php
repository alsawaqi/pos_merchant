<?php

declare(strict_types=1);

/**
 * LAUNCH-P5 B4 — the Hours report from clock in / clock out
 * (pos_staff_attendance): per person and Muscat day, with totals; a record
 * with no clock-out after 16 hours (or flagged by pos_api) stands out and is
 * not counted; corrections need staff.attendance.manage and a reason, and
 * are audited. Before: no report, no edits ("hours" were shift lengths).
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

const P5_HOURS = '/api/reports/hours?date_from=2026-10-04&date_to=2026-10-06';

/** An attendance row written straight to the table (UTC times). Returns its uuid. */
function p5Attendance(Branch $branch, int $staffId, string $in, ?string $out, array $extra = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('pos_staff_attendance')->insert(array_merge([
        'uuid' => $uuid,
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'staff_id' => $staffId,
        'clock_in_at' => $in,
        'clock_out_at' => $out,
        'source' => 'device',
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));

    return $uuid;
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-06 08:00:00'));   // 12:00 in Muscat
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('totals hours per person and Muscat day, and flags a missing clock-out', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $huda = p5Staff($ctx['branch'], 'Huda');
    $omar = p5Staff($ctx['branch'], 'Omar');

    // Huda, Muscat 4 Oct: 08:00–12:30 and 17:00–21:00 (8.5 h).
    p5Attendance($ctx['branch'], $huda, '2026-10-04 04:00:00', '2026-10-04 08:30:00');
    p5Attendance($ctx['branch'], $huda, '2026-10-04 13:00:00', '2026-10-04 17:00:00');
    // Huda: 20:30 UTC on 4 Oct is 00:30 on 5 Oct in Muscat → day 5 Oct, 3 h.
    p5Attendance($ctx['branch'], $huda, '2026-10-04 20:30:00', '2026-10-04 23:30:00');
    // Omar forgot to clock out on 5 Oct (now is 6 Oct 12:00 Muscat, >16 h later).
    $stale = p5Attendance($ctx['branch'], $omar, '2026-10-05 05:00:00', null);
    // Omar is at work right now (2 h ago): open, not a problem.
    $open = p5Attendance($ctx['branch'], $omar, '2026-10-06 06:00:00', null);
    // Outside the window (3 Oct Muscat).
    p5Attendance($ctx['branch'], $huda, '2026-10-03 05:00:00', '2026-10-03 09:00:00');

    $data = $this->getJson(P5_HOURS)->assertOk()->json('data');

    expect($data['window']['timezone'])->toBe('Asia/Muscat')
        ->and($data['summary'])->toBe(['people' => 2, 'records' => 5, 'total_hours' => 11.5, 'no_clock_out' => 1, 'open' => 1]);

    $people = collect($data['people'])->keyBy('staff_name');
    expect($people['Huda'])->toMatchArray(['records' => 3, 'days' => 2, 'hours' => 11.5, 'no_clock_out' => 0])
        ->and($people['Omar'])->toMatchArray(['records' => 2, 'days' => 2, 'hours' => 0, 'no_clock_out' => 1]);

    $days = collect($data['days'])->map(fn ($d) => [$d['staff_name'], $d['day'], $d['hours'], $d['records'], $d['no_clock_out']])->all();
    expect($days)->toBe([
        ['Huda', '2026-10-04', 8.5, 2, false],
        ['Huda', '2026-10-05', 3, 1, false],
        ['Omar', '2026-10-05', 0, 1, true],
        ['Omar', '2026-10-06', 0, 1, false],
    ]);

    $rows = collect($data['rows'])->keyBy('uuid');
    expect($rows[$stale])->toMatchArray(['no_clock_out' => true, 'open' => false, 'hours' => null, 'clock_in_local' => '2026-10-05 09:00', 'clock_out_local' => null])
        ->and($rows[$open])->toMatchArray(['no_clock_out' => false, 'open' => true]);
});

it('honours pos_api\'s no-clock-out flag in either JSON shape, and filters by person and branch', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $b2 = p5Branch($ctx['company'], 'Seeb');
    $huda = p5Staff($ctx['branch'], 'Huda');
    $omar = p5Staff($b2, 'Omar');
    $listFlag = p5Attendance($ctx['branch'], $huda, '2026-10-06 06:00:00', null, ['flags' => json_encode(['no_clock_out'])]);
    $objectFlag = p5Attendance($b2, $omar, '2026-10-06 07:00:00', null, ['flags' => json_encode(['no_clock_out' => true, 'late' => false])]);

    $rows = collect($this->getJson(P5_HOURS)->assertOk()->json('data.rows'))->keyBy('uuid');
    expect($rows[$listFlag]['no_clock_out'])->toBeTrue()
        ->and($rows[$objectFlag]['no_clock_out'])->toBeTrue()
        ->and($rows[$objectFlag]['flags'])->toBe(['no_clock_out']);

    expect(collect($this->getJson(P5_HOURS."&staff_id={$omar}")->json('data.rows'))->pluck('uuid')->all())->toBe([$objectFlag])
        ->and(collect($this->getJson(P5_HOURS."&branch_ids[]={$ctx['branch']->id}")->json('data.rows'))->pluck('uuid')->all())->toBe([$listFlag]);

    // Another company sees nothing of this; reports.view is needed.
    makeMerchantActor(MerchantRole::Manager->value);
    expect($this->getJson(P5_HOURS)->assertOk()->json('data.rows'))->toBe([]);
    p5ActorWith(['pos_staff.view']);
    $this->getJson(P5_HOURS)->assertForbidden();
});

it('corrects a clock-in / clock-out with a reason, in Muscat time, audited', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $omar = p5Staff($ctx['branch'], 'Omar');
    $uuid = p5Attendance($ctx['branch'], $omar, '2026-10-05 05:00:00', null);

    $this->patchJson("/api/attendance/{$uuid}", [
        'clock_in_at' => '2026-10-05 09:00',
        'clock_out_at' => '2026-10-05 17:15',
        'reason' => 'Forgot to clock out; left at 17:15 per the manager',
    ])->assertOk()->assertJsonPath('data.clock_out_local', '2026-10-05 17:15');

    $row = DB::table('pos_staff_attendance')->where('uuid', $uuid)->first();
    expect(Carbon::parse($row->clock_out_at)->format('Y-m-d H:i'))->toBe('2026-10-05 13:15')   // UTC
        ->and((int) $row->edited_by_user_id)->toBe($ctx['user']->id)
        ->and($row->edit_reason)->toBe('Forgot to clock out; left at 17:15 per the manager')
        ->and($row->source)->toBe('device');

    $audit = DB::table('pos_audit_logs')->where('event', 'staff.attendance.edited')->sole();
    expect(json_decode($audit->old_values, true))->toBe(['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => null])
        ->and(json_decode($audit->new_values, true))->toBe(['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => '2026-10-05 17:15'])
        ->and(json_decode($audit->metadata, true)['reason'])->toBe('Forgot to clock out; left at 17:15 per the manager')
        ->and((int) $audit->branch_id)->toBe($ctx['branch']->id);

    $report = collect($this->getJson(P5_HOURS)->json('data.rows'))->firstWhere('uuid', $uuid);
    expect($report)->toMatchArray(['hours' => 8.25, 'no_clock_out' => false, 'edited' => true, 'edited_by' => $ctx['user']->name]);
});

it('refuses an edit without the permission, without a reason, or with impossible times', function (array $body, int $status): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $uuid = p5Attendance($ctx['branch'], p5Staff($ctx['branch'], 'Omar'), '2026-10-05 05:00:00', null);

    $this->patchJson("/api/attendance/{$uuid}", $body)->assertStatus($status);
    expect(DB::table('pos_staff_attendance')->where('uuid', $uuid)->value('edit_reason'))->toBeNull()
        ->and(DB::table('pos_audit_logs')->where('event', 'staff.attendance.edited')->count())->toBe(0);
})->with([
    'no reason' => [['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => '2026-10-05 17:00', 'reason' => ''], 422],
    'out before in' => [['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => '2026-10-05 08:00', 'reason' => 'fix it'], 422],
    'in the future' => [['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => '2026-10-06 18:00', 'reason' => 'fix it'], 422],
    'over 24 hours' => [['clock_in_at' => '2026-10-04 08:00', 'clock_out_at' => '2026-10-05 09:00', 'reason' => 'fix it'], 422],
    'bad format' => [['clock_in_at' => '2026-10-05T09:00:00Z', 'clock_out_at' => null, 'reason' => 'fix it'], 422],
]);

it('needs staff.attendance.manage to edit (reports.view only reads) and keeps branch-limited users to their branches', function (): void {
    $ctx = p5ActorWith(['reports.view']);
    $uuid = p5Attendance($ctx['branch'], p5Staff($ctx['branch'], 'Omar'), '2026-10-05 05:00:00', null);
    $this->getJson(P5_HOURS)->assertOk();
    $this->patchJson("/api/attendance/{$uuid}", ['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => '2026-10-05 17:00', 'reason' => 'fix it'])->assertForbidden();

    $owner = makeMerchantActor(MerchantRole::Manager->value);
    $b2 = p5Branch($owner['company'], 'Seeb');
    $other = p5Attendance($b2, p5Staff($b2, 'Nasser'), '2026-10-05 05:00:00', null);
    $limited = User::factory()->create(['company_id' => $owner['company']->id, 'user_type' => 'merchant', 'status' => 'active', 'branch_scope_json' => [$owner['branch']->id]]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($owner['company']->id);
    $limited->assignRole(MerchantRole::Manager->value);
    $this->actingAs($limited);
    $this->patchJson("/api/attendance/{$other}", ['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => '2026-10-05 17:00', 'reason' => 'fix it'])->assertForbidden();
    expect($this->getJson(P5_HOURS)->assertOk()->json('data.rows'))->toBe([]);

    // Another company's row is not found.
    $this->actingAs($ctx['user']);
    app(MerchantTenantContext::class)->set($ctx['company']->id);
    $manager = makeMerchantActor(MerchantRole::Manager->value);
    $this->patchJson("/api/attendance/{$uuid}", ['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => '2026-10-05 17:00', 'reason' => 'fix it'])->assertNotFound();
});

it('exports the hours with the same filters', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    p5Attendance($ctx['branch'], p5Staff($ctx['branch'], 'Huda'), '2026-10-04 04:00:00', '2026-10-04 08:30:00');

    $csv = $this->get('/api/reports/hours/export?date_from=2026-10-04&date_to=2026-10-06&format=csv', ['Accept' => 'application/json'])
        ->assertOk()->getContent();
    expect($csv)->toContain('# people')->and($csv)->toContain('# days')->and($csv)->toContain('# rows')
        ->and($csv)->toContain('Huda')->and($csv)->toContain('4.5')->and($csv)->not->toContain('# options');
});
