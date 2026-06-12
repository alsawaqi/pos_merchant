<?php

declare(strict_types=1);

/**
 * P-G8 — branch performance targets.
 *
 * The window engine (back-to-back windows from starts_on, cumulative
 * goal, lazy idempotent finalization), the confirmed-money rule (paid
 * orders minus pending-reconciliation tenders; pending-verification
 * deliveries excluded by construction), month-boundary safety, the
 * replace-on-create rule, F5 scope, and the permission matrix.
 */

use App\Enums\MerchantRole;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\BranchTarget;
use App\Models\BranchTargetWindow;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A paid order of [$amount] OMR at [$openedAt] on the ctx branch. */
function paidOrder(array $ctx, string $amount, string $openedAt, ?Branch $branch = null): Order
{
    return Order::factory()
        ->for($ctx['company'], 'company')
        ->for($branch ?? $ctx['branch'], 'branch')
        ->paid()
        ->create(['grand_total' => $amount, 'subtotal' => $amount, 'opened_at' => $openedAt]);
}

it('finalizes elapsed day windows lazily and reports the live current window', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();

    // 200/day over 3-day windows from Jun 6: windows 6-8, 9-11, 12-14
    // are closed; 15-17 is live. Goal = 600 cumulative per window.
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day',
        'amount' => 200,
        'window_periods' => 3,
        'starts_on' => '2026-06-06',
    ])->assertCreated();

    paidOrder($ctx, '100.000', '2026-06-07 10:00:00'); // window 1 → miss
    paidOrder($ctx, '650.000', '2026-06-10 10:00:00'); // window 2 → hit (one strong day saves the window)
    // window 3 stays empty → miss
    paidOrder($ctx, '380.000', '2026-06-15 09:00:00'); // current window

    $row = $this->getJson('/api/branch-targets')->assertOk()->json('data.0');

    // Three closed windows finalized, newest first.
    expect($row['window_count'])->toBe(3);
    expect($row['hit_count'])->toBe(1);
    expect(collect($row['history'])->pluck('hit')->all())->toBe([false, true, false]);
    expect($row['history'][1]['actual_amount'])->toBe('650.000');
    expect($row['history'][1]['goal_amount'])->toBe('600.000');

    // The live window: day 1 of 3, 380 / 600.
    expect($row['current']['window_start'])->toBe('2026-06-15');
    expect($row['current']['window_end'])->toBe('2026-06-17');
    expect($row['current']['elapsed_periods'])->toBe(1);
    expect($row['current']['goal'])->toBe('600.000');
    expect($row['current']['actual'])->toBe('380.000');
    expect($row['current']['progress_pct'])->toBe(63);

    // Idempotent: a second fetch finalizes nothing new.
    $this->getJson('/api/branch-targets')->assertOk();
    expect(BranchTargetWindow::query()->count())->toBe(3);

    // The dashboard widget reports the same window + the fresh miss.
    $perf = $this->getJson('/api/branch-targets/performance')->assertOk();
    expect($perf->json('data.0.actual'))->toBe('380.000');
    expect($perf->json('data.0.elapsed_periods'))->toBe(1);
    expect($perf->json('recent_misses'))->toHaveCount(1);
    expect($perf->json('recent_misses.0.window_start'))->toBe('2026-06-12');
});

it('counts confirmed money only', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day',
        'amount' => 100,
        'window_periods' => 1,
        'starts_on' => '2026-06-15',
    ])->assertCreated();

    paidOrder($ctx, '40.000', '2026-06-15 09:00:00');

    // Paid but with a pending-reconciliation tender → NOT confirmed yet.
    $pending = paidOrder($ctx, '25.000', '2026-06-15 10:00:00');
    Payment::factory()->for($pending, 'order')->create([
        'amount' => '25.000',
        'status' => 'pending_reconciliation',
        'pending_reconciliation' => true,
    ]);

    // A pending-verification delivery (F7) is outside by construction.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->delivery()->create([
        'status' => OrderStatus::PendingVerification->value,
        'grand_total' => '99.000',
        'opened_at' => '2026-06-15 11:00:00',
    ]);

    $row = $this->getJson('/api/branch-targets')->assertOk()->json('data.0');
    expect($row['current']['actual'])->toBe('40.000');

    // The admin approves the tender → the order counts on the next read.
    $pending->payments()->update(['pending_reconciliation' => false, 'status' => 'success']);
    $row = $this->getJson('/api/branch-targets')->assertOk()->json('data.0');
    expect($row['current']['actual'])->toBe('65.000');
});

it('handles month windows without end-of-month overflow', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));
    $ctx = makeMerchantActor();

    // Monthly target anchored on Jan 31: window 1 = Jan 31 → Feb 27,
    // window 2 = Feb 28 → Mar 30 (no-overflow month math).
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'month',
        'amount' => 1000,
        'window_periods' => 1,
        'starts_on' => '2026-01-31',
    ])->assertCreated();

    $row = $this->getJson('/api/branch-targets')->assertOk()->json('data.0');
    expect($row['window_count'])->toBe(1);
    expect($row['history'][0]['window_start'])->toBe('2026-01-31');
    expect($row['history'][0]['window_end'])->toBe('2026-02-27');
    expect($row['current']['window_start'])->toBe('2026-02-28');
    expect($row['current']['window_end'])->toBe('2026-03-30');
});

it('replaces the branch\'s active target on create, keeping history', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();

    $first = $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1, 'starts_on' => '2026-06-10',
    ])->assertCreated()->json('data.uuid');

    // Materialise its history before the replacement.
    $this->getJson('/api/branch-targets')->assertOk();
    $firstWindows = BranchTargetWindow::query()->count();
    expect($firstWindows)->toBeGreaterThan(0);

    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'week', 'amount' => 900, 'window_periods' => 2, 'starts_on' => '2026-06-15',
    ])->assertCreated();

    $old = BranchTarget::query()->where('uuid', $first)->firstOrFail();
    expect($old->is_active)->toBeFalse();
    expect(BranchTargetWindow::query()->count())->toBe($firstWindows);
    // Exactly one active target for the branch.
    expect(BranchTarget::query()->where('branch_id', $ctx['branch']->id)->where('is_active', true)->count())->toBe(1);
});

it('updates only the amount and active flag, tenant-scoped', function (): void {
    $ctx = makeMerchantActor();
    $uuid = $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1, 'starts_on' => now()->toDateString(),
    ])->assertCreated()->json('data.uuid');

    $updated = $this->patchJson("/api/branch-targets/{$uuid}", ['amount' => 150])->assertOk();
    expect($updated->json('data.amount'))->toBe('150.000');

    // Another tenant: 404 on update + delete, empty list.
    makeMerchantActor();
    $this->patchJson("/api/branch-targets/{$uuid}", ['amount' => 1])->assertNotFound();
    $this->deleteJson("/api/branch-targets/{$uuid}")->assertNotFound();
    expect($this->getJson('/api/branch-targets')->assertOk()->json('data'))->toHaveCount(0);
});

it('defers finalizing a window holding pending-reconciliation money until the decision lands', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1, 'starts_on' => '2026-06-13',
    ])->assertCreated();

    paidOrder($ctx, '80.000', '2026-06-13 10:00:00');
    $pending = paidOrder($ctx, '50.000', '2026-06-13 11:00:00');
    Payment::factory()->for($pending, 'order')->create([
        'amount' => '50.000',
        'status' => 'pending_reconciliation',
        'pending_reconciliation' => true,
    ]);

    // The Jun-13 window holds unconfirmed money: nothing finalizes (a
    // frozen verdict would lose the 50 forever — approval never re-dates
    // the order). The walk stops there, so Jun-14 waits too.
    $this->getJson('/api/branch-targets')->assertOk();
    expect(BranchTargetWindow::query()->count())->toBe(0);

    // The admin approves → the next read finalizes both windows, with
    // the confirmed money counted: Jun-13 = 130 (hit), Jun-14 = 0 (miss).
    $pending->payments()->update(['pending_reconciliation' => false, 'status' => 'success']);
    $row = $this->getJson('/api/branch-targets')->assertOk()->json('data.0');
    expect($row['window_count'])->toBe(2);
    expect($row['history'][1]['actual_amount'])->toBe('130.000');
    expect($row['history'][1]['hit'])->toBeTrue();
    expect($row['history'][0]['hit'])->toBeFalse();
});

it('seals elapsed windows under the in-force goal before an amount edit', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $uuid = $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1, 'starts_on' => '2026-06-13',
    ])->assertCreated()->json('data.uuid');

    paidOrder($ctx, '150.000', '2026-06-13 10:00:00'); // genuinely hit @100

    // PATCH the amount WITHOUT any prior GET (no lazy finalization ran):
    // the elapsed windows must seal under the goal that was in force.
    $this->patchJson("/api/branch-targets/{$uuid}", ['amount' => 500])->assertOk();

    $windows = BranchTargetWindow::query()->orderBy('window_start')->get();
    expect($windows)->toHaveCount(2); // Jun 13 + Jun 14
    expect((string) $windows[0]->goal_amount)->toBe('100.000');
    expect((bool) $windows[0]->hit)->toBeTrue();

    // The live window uses the NEW goal.
    $row = $this->getJson('/api/branch-targets')->assertOk()->json('data.0');
    expect($row['current']['goal'])->toBe('500.000');
    expect(BranchTargetWindow::query()->count())->toBe(2); // history untouched
});

it('re-activating a target retires the branch\'s other active target and stops evaluating retired ones', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $first = $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1, 'starts_on' => '2026-06-13',
    ])->assertCreated()->json('data.uuid');
    $second = $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 200, 'window_periods' => 1, 'starts_on' => '2026-06-15',
    ])->assertCreated()->json('data.uuid');

    $firstId = BranchTarget::query()->where('uuid', $first)->value('id');
    $sealedCount = BranchTargetWindow::query()->where('target_id', $firstId)->count();
    expect($sealedCount)->toBe(2); // Jun 13 + 14 sealed at replace time

    // Days pass; the RETIRED target accrues nothing new (the engine
    // skips inactive targets — asserted directly, since time-travelling
    // the HTTP session three days trips the freshness middleware).
    $firstTarget = BranchTarget::query()->findOrFail($firstId);
    $finalized = app(App\Actions\Pos\Targets\EvaluateBranchTargetsAction::class)
        ->finalizeDueWindows($firstTarget, Carbon::parse('2026-06-18 12:00:00'));
    expect($finalized)->toBe(0);
    expect(BranchTargetWindow::query()->where('target_id', $firstId)->count())->toBe($sealedCount);

    // Re-activating the first retires the second (one active per branch).
    $this->patchJson("/api/branch-targets/{$first}", ['is_active' => true])->assertOk();
    expect(BranchTarget::query()->where('uuid', $second)->value('is_active'))->toBeFalse();
    expect(BranchTarget::query()->where('branch_id', $ctx['branch']->id)->where('is_active', true)->count())->toBe(1);
});

it('bounds starts_on to a sane horizon', function (): void {
    $ctx = makeMerchantActor();
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1,
        'starts_on' => now()->subYears(2)->toDateString(),
    ])->assertStatus(422);
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1,
        'starts_on' => now()->addYears(2)->toDateString(),
    ])->assertStatus(422);
});

it('applies the F5 branch scope and the permission matrix', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1, 'starts_on' => now()->toDateString(),
    ])->assertCreated();
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $branchB->uuid,
        'period' => 'day', 'amount' => 100, 'window_periods' => 1, 'starts_on' => now()->toDateString(),
    ])->assertCreated();

    // A manager scoped to branch A: list + widget shrink, out-of-scope
    // create 403s.
    $scoped = User::factory()->create([
        'company_id' => $ctx['company']->id, 'user_type' => 'merchant', 'status' => 'active',
        'branch_scope_json' => [$ctx['branch']->id],
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $scoped->assignRole(MerchantRole::Manager->value);
    $this->actingAs($scoped);

    expect($this->getJson('/api/branch-targets')->assertOk()->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/branch-targets/performance')->assertOk()->json('data'))->toHaveCount(1);
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $branchB->uuid,
        'period' => 'day', 'amount' => 50, 'window_periods' => 1, 'starts_on' => now()->toDateString(),
    ])->assertForbidden();

    // A Viewer: no targets.manage → config 403s, but the dashboard
    // widget stays readable.
    $viewer = User::factory()->create([
        'company_id' => $ctx['company']->id, 'user_type' => 'merchant', 'status' => 'active',
    ]);
    $viewer->assignRole(MerchantRole::Viewer->value);
    $this->actingAs($viewer);
    $this->getJson('/api/branch-targets')->assertForbidden();
    $this->postJson('/api/branch-targets', [
        'branch_uuid' => $ctx['branch']->uuid,
        'period' => 'day', 'amount' => 50, 'window_periods' => 1, 'starts_on' => now()->toDateString(),
    ])->assertForbidden();
    expect($this->getJson('/api/branch-targets/performance')->assertOk()->json('data'))->toHaveCount(2);
});
