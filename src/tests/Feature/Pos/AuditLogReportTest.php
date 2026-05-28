<?php

declare(strict_types=1);

/**
 * Phase 7b-5 — Audit Log Viewer coverage (blueprint §5.12).
 *
 * Covers:
 *   - reports.view alone is NOT enough -- audit_log.view required
 *   - Pagination (default 50, configurable per_page)
 *   - Date window scope
 *   - branch_ids scope
 *   - event filter
 *   - actor_id filter
 *   - Tenant isolation (no foreign-company rows ever returned)
 *   - Actor relation is eager-loaded (actor_name + actor_email present)
 *   - Order is created_at DESC (newest first)
 *
 * NOTE: we use DB::table('pos_audit_logs')->insert() rather than
 * AuditLog::create() because the model's $fillable list omits
 * created_at (audit log entries are meant to use NOW()), and the
 * booted() guard blocks save() after the fact. Raw inserts let us
 * place rows precisely in the test window.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Helper: insert a single audit log row at a precise timestamp.
 *
 * @param  array<string, mixed>  $row
 */
function insertAuditLog(array $row): int
{
    return (int) DB::table('pos_audit_logs')->insertGetId(array_merge([
        'event' => 'unspecified',
        'created_at' => now(),
    ], $row));
}

it('is gated under audit_log.view (NOT reports.view)', function (): void {
    // CashierSupervisor gets reports.view in the role matrix
    // but NOT audit_log.view -- exactly the split the blueprint
    // exists to enforce.
    makeMerchantActor(MerchantRole::CashierSupervisor->value);

    $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30')
        ->assertForbidden();
});

it('returns 200 for a Manager (has audit_log.view)', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);

    $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();
});

it('lists audit log rows in created_at DESC order', function (): void {
    $ctx = makeMerchantActor();

    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'discount.created',
        'created_at' => '2026-06-05 10:00:00',
    ]);
    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'discount.updated',
        'created_at' => '2026-06-10 14:00:00',
    ]);

    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(2);
    // Newest first.
    expect($rows[0]['event'])->toBe('discount.updated');
    expect($rows[1]['event'])->toBe('discount.created');
});

it('eager-loads the actor relation (name + email exposed)', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->update(['name' => 'Sara Manager', 'email' => 'sara@example.com']);

    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'login',
        'created_at' => '2026-06-05 10:00:00',
    ]);

    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    expect($response->json('data.rows.0.actor_name'))->toBe('Sara Manager');
    expect($response->json('data.rows.0.actor_email'))->toBe('sara@example.com');
});

it('filters by event when provided', function (): void {
    $ctx = makeMerchantActor();

    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'discount.created',
        'created_at' => '2026-06-05 10:00:00',
    ]);
    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'ingredient.created',
        'created_at' => '2026-06-06 10:00:00',
    ]);

    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30&event=discount.created')
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['event'])->toBe('discount.created');
});

it('filters by actor_id when provided', function (): void {
    $ctx = makeMerchantActor();
    $other = User::factory()->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
    ]);

    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'discount.created',
        'created_at' => '2026-06-05 10:00:00',
    ]);
    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'actor_user_id' => $other->id,
        'event' => 'discount.updated',
        'created_at' => '2026-06-06 10:00:00',
    ]);

    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30&actor_id=' . $other->id)
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['actor_id'])->toBe($other->id);
    expect($rows[0]['event'])->toBe('discount.updated');
});

it('filters by branch_ids when provided', function (): void {
    $ctx = makeMerchantActor();
    $other = Branch::factory()->for($ctx['company'], 'company')->create();

    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'discount.created',
        'created_at' => '2026-06-05 10:00:00',
    ]);
    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'branch_id' => $other->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'discount.updated',
        'created_at' => '2026-06-06 10:00:00',
    ]);

    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30&branch_ids[]=' . $other->id)
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['branch_id'])->toBe($other->id);
});

it('respects the date window', function (): void {
    $ctx = makeMerchantActor();

    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'outside.window',
        'created_at' => '2026-05-15 10:00:00',
    ]);
    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'inside.window',
        'created_at' => '2026-06-15 10:00:00',
    ]);

    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['event'])->toBe('inside.window');
});

it('paginates with configurable per_page', function (): void {
    $ctx = makeMerchantActor();

    for ($i = 0; $i < 5; $i++) {
        insertAuditLog([
            'company_id' => $ctx['company']->id,
            'actor_user_id' => $ctx['user']->id,
            'event' => "evt.{$i}",
            'created_at' => '2026-06-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 10:00:00',
        ]);
    }

    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30&per_page=2')
        ->assertOk();

    expect($response->json('data.rows'))->toHaveCount(2);
    expect($response->json('data.meta.per_page'))->toBe(2);
    expect($response->json('data.meta.last_page'))->toBe(3);
    expect($response->json('data.meta.total'))->toBe(5);

    // Page 2.
    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30&per_page=2&page=2')
        ->assertOk();
    expect($response->json('data.rows'))->toHaveCount(2);
    expect($response->json('data.meta.current_page'))->toBe(2);
});

it('does not leak other tenants audit log rows', function (): void {
    $ctx = makeMerchantActor();

    insertAuditLog([
        'company_id' => $ctx['company']->id,
        'actor_user_id' => $ctx['user']->id,
        'event' => 'mine',
        'created_at' => '2026-06-05 10:00:00',
    ]);

    $foreign = Company::factory()->create();
    insertAuditLog([
        'company_id' => $foreign->id,
        'event' => 'theirs.secret',
        'created_at' => '2026-06-05 10:00:00',
    ]);

    $response = $this->getJson('/api/reports/audit-log?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk();

    $rows = $response->json('data.rows');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['event'])->toBe('mine');
});

it('returns 422 on bad date filters', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);

    $this->getJson('/api/reports/audit-log?date_from=2026-06-30&date_to=2026-06-01')
        ->assertStatus(422)->assertJsonValidationErrors(['date_to']);
});
