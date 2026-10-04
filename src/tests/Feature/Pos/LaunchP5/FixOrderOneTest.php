<?php

declare(strict_types=1);

/**
 * LAUNCH-P5 fix order 1, Part B (pos_merchant) — from the server and portal
 * review (probes MP1–MP3 included in spirit):
 *   F2  a `legacy` approvals row from a device that has already sent auth_v
 *       (pos_devices.auth_v_seen_at) is a problem in the Approvals report;
 *   F7  the Shift report shows late pay-outs, and corrected expected cash =
 *       expected + late sales − late pay-outs;
 *   L4  order_cancel_positions = stored list ∪ order.void_paid, never
 *       narrowed by an unrelated save;
 *   L5  the resolver uses pos_api's no-row rule (defaults + the three old
 *       lists) and floors discount_max_percent (fixture cases generated with
 *       pos_api's own resolver);
 *   L6  an hours edit may not overlap another record of the person, nor
 *       leave a second record open;
 *   L7  K never reaches an exception message during hire or reset.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A device row for the company's branch; auth_v_seen_at set when $p5. */
function p5Device(Branch $branch, bool $p5): int
{
    return (int) DB::table('pos_devices')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id, 'branch_id' => $branch->id,
        'name' => $p5 ? 'T3 (P5)' : 'T3 (old)', 'device_type' => 'cashier', 'status' => 'active',
        'auth_v_seen_at' => $p5 ? '2026-10-04 06:00:00' : null, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------- F2

it('F2: counts a legacy row from a device that already sent auth_v as a problem, and only that legacy row', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $p5 = p5Device($ctx['branch'], true);
    $old = p5Device($ctx['branch'], false);

    $downgrade = p5Approval($ctx['branch'], 'table.cancel_line', 'legacy', ['device_id' => $p5, 'approved_at' => '2026-10-04 09:00:00']);
    $oldApp = p5Approval($ctx['branch'], 'table.cancel_line', 'legacy', ['device_id' => $old, 'approved_at' => '2026-10-04 10:00:00']);
    $noDevice = p5Approval($ctx['branch'], 'comp', 'legacy', ['device_id' => null, 'approved_at' => '2026-10-04 11:00:00']);
    $failed = p5Approval($ctx['branch'], 'comp', 'failed', ['device_id' => $p5, 'approved_at' => '2026-10-04 12:00:00']);

    $data = $this->getJson('/api/reports/approvals?date_from=2026-10-04&date_to=2026-10-04')->assertOk()->json('data');
    $rows = collect($data['rows'])->keyBy('id');

    expect($rows[$downgrade])->toMatchArray(['problem' => true, 'legacy_from_p5_device' => true])
        ->and($rows[$oldApp])->toMatchArray(['problem' => false, 'legacy_from_p5_device' => false])
        ->and($rows[$noDevice])->toMatchArray(['problem' => false, 'legacy_from_p5_device' => false])
        ->and($rows[$failed]['problem'])->toBeTrue()
        ->and($data['summary'])->toMatchArray(['legacy' => 3, 'failed' => 1, 'legacy_from_p5_devices' => 1, 'problems' => 2])
        ->and($data['by_day'])->toBe([['day' => '2026-10-04', 'total' => 4, 'problems' => 2]]);

    $problems = collect($this->getJson('/api/reports/approvals?date_from=2026-10-04&date_to=2026-10-04&result=problems')->assertOk()->json('data.rows'))
        ->pluck('id')->sort()->values()->all();
    expect($problems)->toBe(collect([$downgrade, $failed])->sort()->values()->all());
});

// ---------------------------------------------------------------- F7

it('F7: shows late pay-outs and takes them off the corrected expected cash; a re-open clears them', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-04 13:00:00'));
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $uuid = (string) Str::uuid();
    DB::table('pos_shifts')->insert([
        'uuid' => $uuid, 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'status' => 'closed',
        'opened_at' => '2026-10-04 04:00:00', 'closed_at' => '2026-10-04 12:00:00', 'opening_cash' => '20.000',
        'expected_cash' => '95.000', 'closing_cash' => '97.500', 'variance' => '2.500',
        'late_sales_baisas' => 5000, 'late_payouts_baisas' => 2500, 'needs_review' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = $this->getJson('/api/reports/shifts?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->json('data');
    $row = collect($data['shifts'])->firstWhere('uuid', $uuid);
    expect($row)->toMatchArray([
        'late_sales' => '5.000',
        'late_payouts' => '2.500',
        'corrected_expected_cash' => '97.500',   // 95 + 5 − 2.5
        'corrected_variance' => '0.000',
    ])->and($data['summary']['total_late_payouts'])->toBe('2.500');

    $this->postJson("/api/shifts/{$uuid}/reopen")->assertOk();
    expect((int) DB::table('pos_shifts')->where('uuid', $uuid)->value('late_payouts_baisas'))->toBe(0);
    $old = json_decode((string) DB::table('pos_audit_logs')->where('event', 'shift.reopened')->value('old_values'), true);
    expect($old['late_payouts_baisas'])->toBe(2500);
});

// ---------------------------------------------------------------- L4

it('L4: an unrelated save never narrows order_cancel_positions; a void_paid tick widens it', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $cid = $ctx['company']->id;
    DB::table('pos_company_settings')->insert(['company_id' => $cid, 'key' => 'order_cancel_positions',
        'value' => json_encode(['cashier', 'supervisor', 'manager']), 'created_at' => now(), 'updated_at' => now()]);

    // Probe MP1: an unrelated tick change.
    $this->putJson('/api/settings/staff-permissions', ['permissions' => ['cashier' => ['actions' => ['stock.count' => false]]]])->assertOk();
    expect(p5CompanySetting($cid, 'order_cancel_positions'))->toBe(['cashier', 'supervisor', 'manager']);

    // Ticking order.void_paid for the waiter adds the waiter.
    $this->putJson('/api/settings/staff-permissions', ['permissions' => ['waiter' => ['actions' => ['order.void_paid' => true]]]])->assertOk();
    expect(p5CompanySetting($cid, 'order_cancel_positions'))->toBe(['cashier', 'waiter', 'supervisor', 'manager']);

    // Unticking it for the manager keeps the manager (the stored list wins).
    $this->putJson('/api/settings/staff-permissions', ['permissions' => ['manager' => ['actions' => ['order.void_paid' => false]]]])->assertOk();
    expect(p5CompanySetting($cid, 'order_cancel_positions'))->toBe(['cashier', 'waiter', 'supervisor', 'manager']);
});

// ---------------------------------------------------------------- L5

it('L5: resolves every corner case exactly like pos_api (fixture generated with its resolver)', function (): void {
    $fixture = p5Fixture('resolver_corner_cases.json');
    expect($fixture['cases'])->not->toBeEmpty();

    foreach ($fixture['cases'] as $case) {
        $ctx = makeMerchantActor(MerchantRole::Manager->value);
        foreach ($case['settings'] as $key => $json) {
            DB::table('pos_company_settings')->insert(['company_id' => $ctx['company']->id, 'key' => $key, 'value' => $json, 'created_at' => now(), 'updated_at' => now()]);
        }

        $matrix = $this->getJson('/api/settings/staff-permissions')->assertOk()->json('data.permissions');
        expect($matrix)->toBe($case['expected'], $case['name']);
    }
});

it('L5: a company with no tick list keeps its old approver list on its first save (probe MP2)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $cid = $ctx['company']->id;
    DB::table('pos_company_settings')->insert(['company_id' => $cid, 'key' => 'manager_approval_positions',
        'value' => json_encode(['supervisor', 'manager']), 'created_at' => now(), 'updated_at' => now()]);

    $shown = $this->getJson('/api/settings/staff-permissions')->assertOk()->json('data.permissions');
    expect($shown['supervisor']['actions']['approvals.give'])->toBeTrue();

    $this->putJson('/api/settings/staff-permissions', ['permissions' => ['cashier' => ['actions' => ['stock.count' => false]]]])->assertOk();
    expect(p5CompanySetting($cid, 'manager_approval_positions'))->toBe(['supervisor', 'manager'])
        ->and(p5CompanySetting($cid, 'position_permissions')['supervisor']['actions']['approvals.give'])->toBeTrue();
    // Hiring into an approving position follows the same matrix.
    expect($this->getJson('/api/pos-staff')->json('meta.approver_positions'))->toBe(['supervisor', 'manager']);
});

// ---------------------------------------------------------------- L6

/** An attendance row (UTC times). */
function p5Clock(Branch $branch, int $staffId, string $in, ?string $out): string
{
    $uuid = (string) Str::uuid();
    DB::table('pos_staff_attendance')->insert(['uuid' => $uuid, 'company_id' => $branch->company_id, 'branch_id' => $branch->id,
        'staff_id' => $staffId, 'clock_in_at' => $in, 'clock_out_at' => $out, 'source' => 'device', 'created_at' => now(), 'updated_at' => now()]);

    return $uuid;
}

it('L6: refuses an hours edit that overlaps another record of the person (probe MP3), and allows touching records', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-06 08:00:00'));
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $omar = p5Staff($ctx['branch'], 'Omar');
    p5Clock($ctx['branch'], $omar, '2026-10-05 04:00:00', '2026-10-05 08:00:00');          // 08:00–12:00 Muscat
    $b = p5Clock($ctx['branch'], $omar, '2026-10-05 09:00:00', '2026-10-05 13:00:00');     // 13:00–17:00 Muscat
    // Another person's record at the same time does not matter.
    p5Clock($ctx['branch'], p5Staff($ctx['branch'], 'Huda'), '2026-10-05 04:00:00', '2026-10-05 13:00:00');

    $this->patchJson("/api/attendance/{$b}", ['clock_in_at' => '2026-10-05 09:00', 'clock_out_at' => '2026-10-05 17:00', 'reason' => 'typo fix'])
        ->assertStatus(422)->assertJsonValidationErrors(['clock_in_at']);
    $hours = collect($this->getJson('/api/reports/hours?date_from=2026-10-05&date_to=2026-10-05')->json('data.people'))->firstWhere('staff_name', 'Omar')['hours'];
    expect((float) $hours)->toBe(8.0);

    // Touching (12:00) is fine.
    $this->patchJson("/api/attendance/{$b}", ['clock_in_at' => '2026-10-05 12:00', 'clock_out_at' => '2026-10-05 17:00', 'reason' => 'came back at noon'])->assertOk();
});

it('L6: refuses clearing a clock-out while another record of the person is open, or an open record before a later one', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-06 08:00:00'));
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $omar = p5Staff($ctx['branch'], 'Omar');
    $closed = p5Clock($ctx['branch'], $omar, '2026-10-05 04:00:00', '2026-10-05 08:00:00');
    p5Clock($ctx['branch'], $omar, '2026-10-06 05:00:00', null);                              // at work now

    $this->patchJson("/api/attendance/{$closed}", ['clock_in_at' => '2026-10-05 08:00', 'clock_out_at' => null, 'reason' => 'still at work?'])
        ->assertStatus(422)->assertJsonValidationErrors(['clock_out_at']);
    expect(DB::table('pos_staff_attendance')->where('uuid', $closed)->value('clock_out_at'))->not->toBeNull();

    // A lone record can be left open.
    $huda = p5Staff($ctx['branch'], 'Huda');
    $lone = p5Clock($ctx['branch'], $huda, '2026-10-06 05:00:00', '2026-10-06 07:00:00');
    $this->patchJson("/api/attendance/{$lone}", ['clock_in_at' => '2026-10-06 09:00', 'clock_out_at' => null, 'reason' => 'still at work'])->assertOk();

    // An open record that would run into a later record of the same person overlaps it.
    $sara = p5Staff($ctx['branch'], 'Sara');
    $early = p5Clock($ctx['branch'], $sara, '2026-10-05 04:00:00', '2026-10-05 06:00:00');
    p5Clock($ctx['branch'], $sara, '2026-10-05 08:00:00', '2026-10-05 12:00:00');
    $this->patchJson("/api/attendance/{$early}", ['clock_in_at' => '2026-10-05 08:00', 'clock_out_at' => null, 'reason' => 'forgot'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------- L7

/** SQLite triggers that make any write of K fail (insert, or an update that changes it). */
function p5RefuseVerifierWrites(): void
{
    DB::statement("CREATE TRIGGER p5_no_k_insert BEFORE INSERT ON pos_staff WHEN NEW.pin_offline_key IS NOT NULL BEGIN SELECT RAISE(ABORT, 'refused by the test'); END");
    DB::statement("CREATE TRIGGER p5_no_k_update BEFORE UPDATE ON pos_staff WHEN NEW.pin_offline_key IS NOT OLD.pin_offline_key BEGIN SELECT RAISE(ABORT, 'refused by the test'); END");
}

/**
 * Every message the request produced (the response body and, without the
 * exception handler, any thrown exception with its chain).
 *
 * @return list<string>
 */
function p5Messages(callable $request): array
{
    $messages = [];
    try {
        $messages[] = (string) $request()->getContent();
    } catch (Throwable $e) {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $messages[] = $x->getMessage();
        }
    }

    return $messages;
}

it('L7: keeps K out of the error when storing the verifier fails during a hire', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    config(['pos.approver_kdf_iterations' => 1000]);
    p5RefuseVerifierWrites();
    $this->withoutExceptionHandling();

    $messages = p5Messages(fn () => $this->postJson('/api/pos-staff', ['name' => 'Salim', 'branch_id' => $ctx['branch']->id, 'position' => 'manager']));

    expect($messages)->not->toBeEmpty();
    foreach ($messages as $message) {
        expect(preg_match('/[0-9a-f]{64}/', $message))->toBe(0, $message);
    }
    expect(implode(' ', $messages))->toContain('Could not store the offline PIN check')
        ->and(DB::table('pos_staff')->where('name', 'Salim')->exists())->toBeFalse();
});

it('L7: keeps K out of the error when storing the verifier fails during a PIN reset', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    config(['pos.approver_kdf_iterations' => 1000]);
    $uuid = DB::table('pos_staff')->where('id', p5Staff($ctx['branch'], 'Salim', 'manager'))->value('uuid');
    $before = DB::table('pos_staff')->where('uuid', $uuid)->value('pin_hash');
    p5RefuseVerifierWrites();
    $this->withoutExceptionHandling();

    $messages = p5Messages(fn () => $this->postJson("/api/pos-staff/{$uuid}/reset-pin"));

    foreach ($messages as $message) {
        expect(preg_match('/[0-9a-f]{64}/', $message))->toBe(0, $message);
    }
    expect(implode(' ', $messages))->toContain('Could not store the offline PIN check')
        ->and(DB::table('pos_staff')->where('uuid', $uuid)->value('pin_hash'))->toBe($before);   // rolled back
});
