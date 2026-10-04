<?php

declare(strict_types=1);

/**
 * LAUNCH-P5 B5 — the Shift report shows who closed each drawer, pay-outs,
 * cash sales that arrived after the close, the "needs review" flag and the
 * corrected expected cash; a re-open uses the Muscat business day and clears
 * the close data pos_api will recompute. Before: none of these columns, and
 * "same day" was the UTC day.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** A shift row written straight to the table. Returns [id, uuid]. */
function p5Shift(Branch $branch, array $extra = []): array
{
    $uuid = (string) Str::uuid();
    $id = (int) DB::table('pos_shifts')->insertGetId(array_merge([
        'uuid' => $uuid,
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'status' => 'closed',
        'opened_at' => '2026-10-04 04:00:00',
        'closed_at' => '2026-10-04 12:00:00',
        'opening_cash' => '20.000',
        'expected_cash' => '95.000',
        'closing_cash' => '100.000',
        'variance' => '5.000',
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));

    return [$id, $uuid];
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows who closed the drawer, pay-outs, late sales, needs review and the corrected expected cash', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-10 08:00:00'));
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $huda = p5Staff($ctx['branch'], 'Huda');
    $salim = p5Staff($ctx['branch'], 'Salim', 'supervisor');

    // Huda's drawer, closed by Salim at the handover: 7.500 paid out, and a
    // 5.000 cash sale reached the server after the close.
    [, $flagged] = p5Shift($ctx['branch'], [
        'staff_id' => $huda, 'closed_by_staff_id' => $salim, 'payouts_baisas' => 7500,
        'late_sales_baisas' => 5000, 'needs_review' => true, 'note' => 'late cash sale after close',
    ]);
    [, $clean] = p5Shift($ctx['branch'], [
        'staff_id' => $huda, 'closed_by_staff_id' => $huda, 'opened_at' => '2026-10-05 04:00:00', 'closed_at' => '2026-10-05 12:00:00',
        'expected_cash' => '50.000', 'closing_cash' => '49.000', 'variance' => '-1.000',
    ]);

    $data = $this->getJson('/api/reports/shifts?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->json('data');
    $rows = collect($data['shifts'])->keyBy('uuid');

    expect($rows[$flagged])->toMatchArray([
        'staff_name' => 'Huda',
        'closed_by_name' => 'Salim',
        'payouts' => '7.500',
        'late_sales' => '5.000',
        'needs_review' => true,
        'note' => 'late cash sale after close',
        'expected_cash' => '95.000',              // what the Z printed
        'corrected_expected_cash' => '100.000',   // + the late cash sale
        'variance' => '5.000',
        'corrected_variance' => '0.000',          // the drawer was right after all
    ]);
    expect($rows[$clean])->toMatchArray([
        'closed_by_name' => 'Huda', 'payouts' => '0.000', 'late_sales' => '0.000', 'needs_review' => false,
        'corrected_expected_cash' => '50.000', 'corrected_variance' => '-1.000',
    ]);
    expect($data['summary'])->toMatchArray([
        'needs_review_count' => 1,
        'total_payouts' => '7.500',
        'total_late_sales' => '5.000',
        'total_variance' => '4.000',
        'total_corrected_variance' => '-1.000',
        'total_corrected_short' => '-1.000',
    ]);
});

it('re-opens a shift closed after midnight Muscat time that morning, though the UTC day differs', function (): void {
    // Closed 21:30 UTC on 4 Oct = 01:30 on 5 Oct in Muscat; now 08:00 Muscat
    // on 5 Oct (04:00 UTC): different UTC days, the same Muscat day → allowed.
    Carbon::setTestNow(Carbon::parse('2026-10-05 04:00:00'));
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    [, $lateNight] = p5Shift($ctx['branch'], ['closed_at' => '2026-10-04 21:30:00']);
    [, $twin] = p5Shift($ctx['branch'], ['closed_at' => '2026-10-04 21:30:00']);

    $this->postJson("/api/shifts/{$lateNight}/reopen")->assertOk();
    expect(DB::table('pos_shifts')->where('uuid', $lateNight)->value('status'))->toBe('open');
    // The report offers the same re-open (the page follows this flag).
    expect(collect($this->getJson('/api/reports/shifts?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->json('data.shifts'))->firstWhere('uuid', $twin)['reopenable'] ?? null)->toBeTrue();
});

it('refuses a re-open after midnight Muscat time, though the UTC day is the same', function (): void {
    // Closed 19:00 UTC on 5 Oct = 23:00 Muscat on 5 Oct; now 01:00 Muscat on
    // 6 Oct (21:00 UTC on 5 Oct): the same UTC day, a new Muscat day → refused.
    Carbon::setTestNow(Carbon::parse('2026-10-05 21:00:00'));
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    [, $yesterday] = p5Shift($ctx['branch'], ['closed_at' => '2026-10-05 19:00:00']);

    $this->postJson("/api/shifts/{$yesterday}/reopen")->assertUnprocessable();
    expect(DB::table('pos_shifts')->where('uuid', $yesterday)->value('status'))->toBe('closed');
    expect(collect($this->getJson('/api/reports/shifts?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->json('data.shifts'))->firstWhere('uuid', $yesterday)['reopenable'] ?? null)->toBeFalse();
});

it('clears the close data on re-open and keeps it in the audit row', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-04 13:00:00'));
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $salim = p5Staff($ctx['branch'], 'Salim', 'supervisor');
    [$id, $uuid] = p5Shift($ctx['branch'], [
        'closed_by_staff_id' => $salim, 'payouts_baisas' => 7500, 'late_sales_baisas' => 5000, 'needs_review' => true,
    ]);

    $this->postJson("/api/shifts/{$uuid}/reopen")->assertOk();

    $row = DB::table('pos_shifts')->where('id', $id)->first();
    expect($row->status)->toBe('open')
        ->and($row->closed_by_staff_id)->toBeNull()
        ->and((int) $row->payouts_baisas)->toBe(0)
        ->and((int) $row->late_sales_baisas)->toBe(0)
        ->and((bool) $row->needs_review)->toBeFalse();

    $old = json_decode((string) DB::table('pos_audit_logs')->where('event', 'shift.reopened')->value('old_values'), true);
    expect($old)->toMatchArray(['closed_by_staff_id' => $salim, 'needs_review' => true, 'late_sales_baisas' => 5000, 'payouts_baisas' => 7500]);
});
