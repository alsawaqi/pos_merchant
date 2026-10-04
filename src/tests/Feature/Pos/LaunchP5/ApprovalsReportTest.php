<?php

declare(strict_types=1);

/**
 * LAUNCH-P5 B3 — the Approvals report (from pos_approvals: filters by branch,
 * date, action, approver, actor and result; failed / missing / unverifiable
 * stand out; export), the Comp report's approver column (only a checked
 * approval is credited; the old rows held the cashier, B1), and Staff
 * Activity "voids" = voids the person performed (pos_orders.voided_by_staff_id,
 * L5). Before: no report, comps credited to whoever the row named (the
 * cashier), and voids counted for whoever rang the order up.
 */

use App\Enums\MerchantRole;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

const P5_APPROVALS = '/api/reports/approvals?date_from=2026-10-01&date_to=2026-10-31';

/** A company with two branches, a cashier and a manager, and six approvals rows. */
function p5ApprovalsWorld(): array
{
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $b2 = p5Branch($ctx['company'], 'Seeb');
    $cashier = p5Staff($ctx['branch'], 'Huda', 'cashier');
    $manager = p5Staff($ctx['branch'], 'Salim', 'manager');
    $seeb = p5Staff($b2, 'Nasser', 'cashier');

    $ids = [
        'verified' => p5Approval($ctx['branch'], 'discount.manual', 'verified', ['actor_staff_id' => $cashier, 'approver_staff_id' => $manager, 'amount' => '1.250', 'ref' => 'discount:0', 'approved_at' => '2026-10-04 09:00:00']),
        'failed' => p5Approval($ctx['branch'], 'order.void_paid', 'failed', ['actor_staff_id' => $cashier, 'approver_staff_id' => $manager, 'reason' => 'proof mismatch', 'approved_at' => '2026-10-04 10:00:00']),
        'missing' => p5Approval($ctx['branch'], 'comp', 'missing', ['actor_staff_id' => $cashier, 'mode' => 'approval', 'method' => null, 'approved_at' => null, 'created_at' => '2026-10-04 11:00:00']),
        'position_ok' => p5Approval($ctx['branch'], 'comp', 'position_ok', ['actor_staff_id' => $manager, 'mode' => 'position', 'method' => null, 'approved_at' => '2026-10-04 12:00:00']),
        'unverifiable' => p5Approval($b2, 'gift', 'unverifiable', ['actor_staff_id' => $seeb, 'approver_staff_id' => $manager, 'approved_at' => '2026-10-04 13:00:00']),
        'old' => p5Approval($ctx['branch'], 'comp', 'verified', ['actor_staff_id' => $cashier, 'approver_staff_id' => $manager, 'approved_at' => '2026-09-20 09:00:00']),
    ];

    return [...$ctx, 'b2' => $b2, 'cashier' => $cashier, 'manager' => $manager, 'seeb' => $seeb, 'ids' => $ids];
}

it('lists the approvals of the window newest first, with names, and highlights failed, missing and unverifiable', function (): void {
    $w = p5ApprovalsWorld();
    // Another company's row never shows.
    $other = makeMerchantActor(MerchantRole::Manager->value);
    p5Approval($other['branch'], 'comp', 'failed', ['approved_at' => '2026-10-04 09:30:00']);
    $this->actingAs($w['user']);
    app(MerchantTenantContext::class)->set($w['company']->id);

    $data = $this->getJson(P5_APPROVALS)->assertOk()->json('data');

    expect(collect($data['rows'])->pluck('id')->all())->toBe([
        $w['ids']['unverifiable'], $w['ids']['position_ok'], $w['ids']['missing'], $w['ids']['failed'], $w['ids']['verified'],
    ]);
    expect($data['summary'])->toMatchArray(['total' => 5, 'problems' => 3, 'verified' => 1, 'failed' => 1, 'missing' => 1, 'unverifiable' => 1, 'position_ok' => 1, 'legacy' => 0]);

    $rows = collect($data['rows'])->keyBy('id');
    expect($rows[$w['ids']['verified']])->toMatchArray([
        'action' => 'discount.manual', 'result' => 'verified', 'problem' => false, 'actor_name' => 'Huda',
        'approver_name' => 'Salim', 'amount' => '1.250', 'ref' => 'discount:0', 'branch_name' => $w['branch']->name, 'method' => 'offline',
    ]);
    expect($rows[$w['ids']['failed']]['problem'])->toBeTrue()
        ->and($rows[$w['ids']['failed']]['reason'])->toBe('proof mismatch')
        ->and($rows[$w['ids']['missing']]['problem'])->toBeTrue()
        ->and($rows[$w['ids']['missing']]['approved_at'])->toBeNull()
        ->and($rows[$w['ids']['unverifiable']]['problem'])->toBeTrue()
        ->and($rows[$w['ids']['unverifiable']]['branch_name'])->toBe('Seeb')
        ->and($rows[$w['ids']['position_ok']]['problem'])->toBeFalse();

    expect($data['options']['actions'])->toBe(['comp', 'discount.manual', 'gift', 'order.void_paid'])
        ->and(collect($data['options']['staff'])->pluck('name')->all())->toBe(['Huda', 'Nasser', 'Salim']);
});

it('filters by branch, action, approver, actor and result (problems = failed + missing + unverifiable)', function (): void {
    $w = p5ApprovalsWorld();
    $ids = fn (string $query): array => collect($this->getJson(P5_APPROVALS.$query)->assertOk()->json('data.rows'))->pluck('id')->sort()->values()->all();
    $sorted = function (array $values): array {
        sort($values);

        return $values;
    };

    expect($ids("&branch_ids[]={$w['b2']->id}"))->toBe([$w['ids']['unverifiable']])
        ->and($ids('&action=comp'))->toBe($sorted([$w['ids']['missing'], $w['ids']['position_ok']]))
        ->and($ids("&approver_staff_id={$w['manager']}"))->toBe($sorted([$w['ids']['verified'], $w['ids']['failed'], $w['ids']['unverifiable']]))
        ->and($ids("&actor_staff_id={$w['cashier']}"))->toBe($sorted([$w['ids']['verified'], $w['ids']['failed'], $w['ids']['missing']]))
        ->and($ids('&result=failed'))->toBe([$w['ids']['failed']])
        ->and($ids('&result=problems'))->toBe($sorted([$w['ids']['failed'], $w['ids']['missing'], $w['ids']['unverifiable']]));

    // The date window (September holds only the older row).
    expect(collect($this->getJson('/api/reports/approvals?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->json('data.rows'))->pluck('id')->all())
        ->toBe([$w['ids']['old']]);
    $this->getJson(P5_APPROVALS.'&result=maybe')->assertStatus(422);

    // Paging.
    $page = $this->getJson(P5_APPROVALS.'&per_page=2&page=2')->assertOk()->json('data');
    expect($page['meta'])->toBe(['current_page' => 2, 'per_page' => 2, 'last_page' => 3, 'total' => 5])
        ->and(collect($page['rows'])->pluck('id')->all())->toBe([$w['ids']['missing'], $w['ids']['failed']]);
});

it('keeps a branch-limited user to their branches and needs reports.view', function (): void {
    $w = p5ApprovalsWorld();
    $limited = User::factory()->create(['company_id' => $w['company']->id, 'user_type' => 'merchant', 'status' => 'active', 'branch_scope_json' => [$w['b2']->id]]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($w['company']->id);
    $limited->assignRole(MerchantRole::Manager->value);
    $this->actingAs($limited);

    expect(collect($this->getJson(P5_APPROVALS)->assertOk()->json('data.rows'))->pluck('id')->all())->toBe([$w['ids']['unverifiable']]);
    $this->getJson(P5_APPROVALS."&branch_ids[]={$w['branch']->id}")->assertForbidden();

    p5ActorWith(['pos_staff.view']);
    $this->getJson(P5_APPROVALS)->assertForbidden();
});

it('exports every matching row with the same filters', function (): void {
    $w = p5ApprovalsWorld();

    $csv = $this->get('/api/reports/approvals/export?date_from=2026-10-01&date_to=2026-10-31&format=csv&result=problems', ['Accept' => 'application/json'])
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();

    expect($csv)->toContain('# summary')
        ->and($csv)->toContain('# rows')
        ->and($csv)->toContain('proof mismatch')
        ->and(substr_count($csv, ',failed,') + substr_count($csv, ',missing,') + substr_count($csv, ',unverifiable,'))->toBe(3)
        ->and($csv)->not->toContain(',verified,')
        ->and($csv)->not->toContain('# options');
});

it('credits a comp only to an approver the server checked, never to the cashier', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $cashier = p5Staff($ctx['branch'], 'Huda', 'cashier');
    $manager = p5Staff($ctx['branch'], 'Salim', 'manager');
    $supervisor = p5Staff($ctx['branch'], 'Aisha', 'supervisor');

    $comp = function (array $order, array $row) use ($ctx): int {
        $orderId = p5Order($ctx['branch'], array_merge(['opened_at' => '2026-10-04 12:00:00', 'comp_total' => '2.000'], $order));
        DB::table('pos_order_comps')->insert(array_merge([
            'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $orderId,
            'reason_code_snapshot' => 'guest', 'reason_name_snapshot' => 'Guest', 'amount' => '2.000',
            'applied_at' => '2026-10-04 12:00:00', 'created_at' => now(), 'updated_at' => now(),
        ], $row));

        return $orderId;
    };
    $uuid = fn (int $orderId): string => (string) DB::table('pos_orders')->where('id', $orderId)->value('uuid');

    // 1. A verified approval by the manager.
    $o1 = $comp(['staff_id' => $cashier], ['approved_by_pos_staff_id' => $manager]);
    p5Approval($ctx['branch'], 'comp', 'verified', ['subject_type' => 'order', 'subject_uuid' => $uuid($o1), 'actor_staff_id' => $cashier, 'approver_staff_id' => $manager]);
    // 2. A pre-P5 comp: the row names the cashier and nothing was checked.
    $comp(['staff_id' => $cashier], ['approved_by_pos_staff_id' => $cashier, 'amount' => '3.000']);
    // 3. A failed approval.
    $o3 = $comp(['staff_id' => $cashier], ['approved_by_pos_staff_id' => $manager, 'amount' => '5.000']);
    p5Approval($ctx['branch'], 'comp', 'failed', ['subject_uuid' => $uuid($o3), 'actor_staff_id' => $cashier, 'approver_staff_id' => $manager]);
    // 4. A supervisor whose position allows it (position_ok: the actor).
    $o4 = $comp(['staff_id' => $supervisor], ['approved_by_pos_staff_id' => $supervisor, 'amount' => '1.000']);
    p5Approval($ctx['branch'], 'comp', 'position_ok', ['mode' => 'position', 'method' => null, 'subject_uuid' => $uuid($o4), 'actor_staff_id' => $supervisor]);
    // 5. A gift row matches a 'gift' approval, not a 'comp' one.
    $o5 = $comp(['staff_id' => $cashier], ['approved_by_pos_staff_id' => $manager, 'is_gift' => true, 'reason_code_snapshot' => 'gift', 'reason_name_snapshot' => 'Gift', 'amount' => '4.000']);
    p5Approval($ctx['branch'], 'gift', 'verified', ['subject_uuid' => $uuid($o5), 'actor_staff_id' => $cashier, 'approver_staff_id' => $manager]);

    $data = $this->getJson('/api/reports/comps?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->json('data');
    $byStaff = collect($data['by_staff']);

    expect($byStaff->firstWhere('staff_name', 'Salim'))->toMatchArray(['verified' => true, 'value' => '6.000', 'comp_count' => 2])
        ->and($byStaff->firstWhere('staff_name', 'Aisha'))->toMatchArray(['verified' => true, 'value' => '1.000', 'comp_count' => 1])
        ->and($byStaff->firstWhere('staff_name', 'Huda'))->toBeNull()
        ->and($byStaff->firstWhere('verified', false))->toMatchArray(['staff_id' => null, 'value' => '8.000', 'comp_count' => 2]);

    $recent = collect($data['recent'])->keyBy('amount');
    expect($recent['2.000'])->toMatchArray(['approved_by' => 'Salim', 'approval_verified' => true])
        ->and($recent['3.000'])->toMatchArray(['approved_by' => null, 'approval_verified' => false])
        ->and($recent['5.000'])->toMatchArray(['approved_by' => null, 'approval_verified' => false]);
});

it('counts the voids a person performed, not the voided orders they rang up', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $cashier = p5Staff($ctx['branch'], 'Huda', 'cashier');
    $manager = p5Staff($ctx['branch'], 'Salim', 'manager');

    p5Order($ctx['branch'], ['staff_id' => $cashier, 'opened_at' => '2026-10-04 09:00:00']);
    p5Order($ctx['branch'], ['staff_id' => $cashier, 'status' => 'void', 'voided_by_staff_id' => $manager, 'void_approved_by_staff_id' => $manager, 'opened_at' => '2026-10-04 10:00:00']);
    p5Order($ctx['branch'], ['staff_id' => $cashier, 'status' => 'void', 'voided_by_staff_id' => $manager, 'opened_at' => '2026-10-04 11:00:00']);
    // A void from before P5: no voider recorded, counted for nobody.
    p5Order($ctx['branch'], ['staff_id' => $cashier, 'status' => 'void', 'opened_at' => '2026-10-04 12:00:00']);

    $rows = collect($this->getJson('/api/reports/staff-activity?date_from=2026-10-01&date_to=2026-10-31')->assertOk()->json('data.rows'))->keyBy('staff_name');

    expect($rows['Huda']['voids'])->toBe(0)
        ->and($rows['Huda']['orders_paid'])->toBe(1)
        ->and($rows['Salim']['voids'])->toBe(2)          // only voided, rang nothing up
        ->and($rows['Salim']['orders_paid'])->toBe(0);
});
