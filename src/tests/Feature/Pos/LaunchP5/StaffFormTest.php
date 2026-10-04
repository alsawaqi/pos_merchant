<?php

declare(strict_types=1);

/**
 * LAUNCH-P5 B2 — the staff form:
 *   - PIN mint and reset also store the offline approver verifier
 *     (PBKDF2-HMAC-SHA256, 32 bytes, iterations from pos.approver_kdf_iterations,
 *     default 100000), checked against the shared golden vectors; it is never
 *     serialised or logged;
 *   - the PIN mint locks the company row first (L3);
 *   - a branch multi-select (pos_staff_branches; the home branch is always in
 *     it), inside the user's branch scope;
 *   - pos_staff.reset_pin and pos_staff.change_position are split out of
 *     pos_staff.update (M6).
 * Before: no verifier, no lock, one branch per person, and pos_staff.update
 * allowed both a PIN reset and a position change.
 */

use App\Actions\Pos\Staff\MintStaffPinAction;
use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\PosStaff;
use App\Models\User;
use App\Support\ApproverVerifier;
use App\Support\MerchantTenantContext;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** The stored verifier of a staff row, read straight from the table. */
function p5Verifier(string $uuid): array
{
    $row = DB::table('pos_staff')->where('uuid', $uuid)->first(['pin_offline_key', 'pin_offline_salt', 'pin_offline_iterations']);

    return ['key' => $row->pin_offline_key, 'salt' => $row->pin_offline_salt, 'iterations' => $row->pin_offline_iterations === null ? null : (int) $row->pin_offline_iterations];
}

/** Pivot branch ids of a staff row, sorted. */
function p5PivotBranches(string $uuid): array
{
    $id = DB::table('pos_staff')->where('uuid', $uuid)->value('id');
    $ids = DB::table('pos_staff_branches')->where('staff_id', $id)->pluck('branch_id')->map(fn ($v) => (int) $v)->all();
    sort($ids);

    return $ids;
}

it('derives K and the device check exactly as the shared golden vectors', function (): void {
    $goldens = p5Fixture('approval_proof_goldens.json');
    expect($goldens['vectors'])->toHaveCount(3);

    foreach ($goldens['vectors'] as $v) {
        $key = ApproverVerifier::deriveKey($v['pin'], hex2bin($v['salt_hex']), $v['iterations']);
        expect(bin2hex($key))->toBe($v['k_hex'])
            ->and(ApproverVerifier::check($key))->toBe($v['check_hex']);

        $wrong = ApproverVerifier::deriveKey($v['wrong_pin'], hex2bin($v['salt_hex']), $v['iterations']);
        expect(ApproverVerifier::check($wrong))->toBe($v['wrong_pin_check_hex'])
            ->and(ApproverVerifier::check($wrong))->not->toBe($v['check_hex']);
    }

    // Iterations come from pos.approver_kdf_iterations, 100000 by default.
    config(['pos.approver_kdf_iterations' => null]);
    expect(ApproverVerifier::iterations())->toBe(100000);
    config(['pos.approver_kdf_iterations' => 2500]);
    expect(ApproverVerifier::iterations())->toBe(2500);
});

it('stores the offline verifier of the new PIN when hiring', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);
    $ctx = ['branch' => Branch::query()->firstOrFail()];
    config(['pos.approver_kdf_iterations' => 1000]);

    $res = $this->postJson('/api/pos-staff', ['name' => 'Salma', 'branch_id' => $ctx['branch']->id, 'position' => 'manager'])->assertCreated();
    $pin = $res->json('plaintext_pin');
    $v = p5Verifier($res->json('data.uuid'));

    expect($pin)->toMatch('/^\d{6}$/')
        ->and($v['iterations'])->toBe(1000)
        ->and($v['salt'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($v['key'])->toBe(bin2hex(hash_pbkdf2('sha256', $pin, hex2bin($v['salt']), 1000, 32, true)));
});

it('stores a fresh verifier for the new PIN on reset', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);
    $branch = Branch::query()->firstOrFail();
    config(['pos.approver_kdf_iterations' => 1000]);
    $uuid = $this->postJson('/api/pos-staff', ['name' => 'Salma', 'branch_id' => $branch->id, 'position' => 'cashier'])->assertCreated()->json('data.uuid');
    $before = p5Verifier($uuid);

    config(['pos.approver_kdf_iterations' => 1200]);
    $pin = $this->postJson("/api/pos-staff/{$uuid}/reset-pin")->assertOk()->json('plaintext_pin');
    $after = p5Verifier($uuid);

    expect($after['iterations'])->toBe(1200)
        ->and($after['salt'])->not->toBe($before['salt'])
        ->and($after['key'])->toBe(bin2hex(hash_pbkdf2('sha256', $pin, hex2bin($after['salt']), 1200, 32, true)));
});

it('never shows or logs the verifier', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);
    $branch = Branch::query()->firstOrFail();
    config(['pos.approver_kdf_iterations' => 1000]);

    $created = $this->postJson('/api/pos-staff', ['name' => 'Salma', 'branch_id' => $branch->id, 'position' => 'cashier'])->assertCreated();
    $uuid = $created->json('data.uuid');
    $reset = $this->postJson("/api/pos-staff/{$uuid}/reset-pin")->assertOk();
    $list = $this->getJson('/api/pos-staff')->assertOk();
    $edit = $this->patchJson("/api/pos-staff/{$uuid}", ['name' => 'Salma A.'])->assertOk();

    $verifier = p5Verifier($uuid);
    expect($verifier['key'])->not->toBeNull();
    foreach ([$created, $reset, $list, $edit] as $response) {
        expect($response->getContent())->not->toContain('pin_offline')
            ->and($response->getContent())->not->toContain($verifier['key'])
            ->and($response->getContent())->not->toContain($verifier['salt']);
    }
    $audit = DB::table('pos_audit_logs')->get()->map(fn ($r) => (string) $r->old_values.(string) $r->new_values.(string) $r->metadata)->implode(' ');
    expect($audit)->not->toContain('pin_offline')->and($audit)->not->toContain($verifier['key']);
    expect(PosStaff::query()->where('uuid', $uuid)->firstOrFail()->toArray())
        ->not->toHaveKeys(['pin_hash', 'pin_offline_key', 'pin_offline_salt', 'pin_offline_iterations']);
});

it('locks the company row before checking the new PIN is unique (L3)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    config(['pos.approver_kdf_iterations' => 1000]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
    });
    $this->postJson('/api/pos-staff', ['name' => 'Salma', 'branch_id' => $ctx['branch']->id, 'position' => 'cashier'])->assertCreated();

    $pluck = collect($queries)->search(fn ($q) => str_starts_with($q['sql'], 'select "pin_hash" from "pos_staff"'));
    expect($pluck)->not->toBeFalse();
    expect($queries[$pluck - 1]['sql'])->toBe('select "id" from "pos_companies" where "id" = ? limit 1')
        ->and($queries[$pluck - 1]['level'])->toBeGreaterThan(1);   // inside the hire transaction (RefreshDatabase holds level 1)

    // On PostgreSQL that query takes the row lock.
    $builder = app(MintStaffPinAction::class)->lockQuery($ctx['company']->id);
    expect($builder->lock)->toBeTrue()
        ->and((new PostgresGrammar(DB::connection()))->compileSelect($builder))->toEndWith('for update');
});

it('hires into several branches, home branch first and always included', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $b2 = p5Branch($ctx['company'], 'Seeb');
    $b3 = p5Branch($ctx['company'], 'Sohar');

    $res = $this->postJson('/api/pos-staff', [
        'name' => 'Salma', 'branch_id' => $ctx['branch']->id, 'position' => 'manager', 'branch_ids' => [$b3->id, $b2->id],
    ])->assertCreated();
    expect(p5PivotBranches($res->json('data.uuid')))->toBe([$ctx['branch']->id, $b2->id, $b3->id])
        ->and(collect($res->json('data.branches'))->pluck('id')->all())->toBe([$ctx['branch']->id, $b2->id, $b3->id])
        ->and($res->json('data.branches.0.home'))->toBeTrue()
        ->and($res->json('data.branches.1.name'))->toBe('Seeb');
    $audit = json_decode((string) DB::table('pos_audit_logs')->where('event', 'pos_staff.created')->value('new_values'), true);
    expect($audit['branch_ids'])->toBe([$ctx['branch']->id, $b2->id, $b3->id]);

    // No list: the home branch only.
    $solo = $this->postJson('/api/pos-staff', ['name' => 'Omar', 'branch_id' => $b2->id, 'position' => 'cashier'])->assertCreated();
    expect(p5PivotBranches($solo->json('data.uuid')))->toBe([$b2->id]);
});

it('edits the branch list, keeps the home branch in it, and audits the change', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $home = $ctx['branch'];
    $b2 = p5Branch($ctx['company'], 'Seeb');
    $b3 = p5Branch($ctx['company'], 'Sohar');
    $uuid = $this->postJson('/api/pos-staff', ['name' => 'Salma', 'branch_id' => $home->id, 'position' => 'cashier', 'branch_ids' => [$b2->id]])->assertCreated()->json('data.uuid');

    // The form unticks the home branch: it stays.
    $this->patchJson("/api/pos-staff/{$uuid}", ['branch_ids' => [$b3->id]])->assertOk();
    expect(p5PivotBranches($uuid))->toBe([$home->id, $b3->id]);
    $row = DB::table('pos_audit_logs')->where('event', 'pos_staff.updated')->latest('id')->first();
    expect(json_decode($row->old_values, true)['branch_ids'])->toBe([$home->id, $b2->id])
        ->and(json_decode($row->new_values, true)['branch_ids'])->toBe([$home->id, $b3->id]);

    // A home move without a list (an old client) replaces the old home.
    $this->patchJson("/api/pos-staff/{$uuid}", ['branch_id' => $b2->id])->assertOk()
        ->assertJsonPath('data.branch.id', $b2->id);
    expect(p5PivotBranches($uuid))->toBe([$b2->id, $b3->id]);

    // Another company's branch is refused.
    $other = makeMerchantActor(MerchantRole::Manager->value);
    $this->actingAs($ctx['user']);
    app(MerchantTenantContext::class)->set($ctx['company']->id);
    $this->patchJson("/api/pos-staff/{$uuid}", ['branch_ids' => [$other['branch']->id]])->assertStatus(422);
    expect(p5PivotBranches($uuid))->toBe([$b2->id, $b3->id]);
});

it('keeps a branch-limited user to their own branches and leaves the others untouched', function (): void {
    $owner = makeMerchantActor(MerchantRole::Manager->value);
    $b1 = $owner['branch'];
    $b2 = p5Branch($owner['company'], 'Seeb');
    $b3 = p5Branch($owner['company'], 'Sohar');
    $uuid = $this->postJson('/api/pos-staff', ['name' => 'Salma', 'branch_id' => $b1->id, 'position' => 'cashier', 'branch_ids' => [$b3->id]])->assertCreated()->json('data.uuid');

    // A user limited to B1 + B2, in the same company.
    $limited = User::factory()->create(['company_id' => $owner['company']->id, 'user_type' => 'merchant', 'status' => 'active', 'branch_scope_json' => [$b1->id, $b2->id]]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($owner['company']->id);
    $limited->assignRole(MerchantRole::Manager->value);
    $this->actingAs($limited);

    // Adds B2; B3 (outside the scope) is kept.
    $this->patchJson("/api/pos-staff/{$uuid}", ['branch_ids' => [$b1->id, $b2->id]])->assertOk();
    expect(p5PivotBranches($uuid))->toBe([$b1->id, $b2->id, $b3->id]);

    // Asking for B3 explicitly is refused.
    $this->patchJson("/api/pos-staff/{$uuid}", ['branch_ids' => [$b1->id, $b3->id]])->assertForbidden();
    $this->postJson('/api/pos-staff', ['name' => 'Omar', 'branch_id' => $b1->id, 'position' => 'cashier', 'branch_ids' => [$b3->id]])->assertForbidden();
    expect(DB::table('pos_staff')->where('name', 'Omar')->exists())->toBeFalse();
});

it('lists a person working at a branch on that branch page, even when it is not their home', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $b2 = p5Branch($ctx['company'], 'Seeb');
    $this->postJson('/api/pos-staff', ['name' => 'Salma', 'branch_id' => $ctx['branch']->id, 'position' => 'cashier', 'branch_ids' => [$b2->id]])->assertCreated();
    p5Staff($ctx['branch'], 'Home only');

    $names = collect($this->getJson("/api/pos/branches/{$b2->uuid}/staff")->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Salma']);
});

it('splits PIN reset and position change out of pos_staff.update', function (): void {
    $ctx = p5ActorWith(['pos_staff.view', 'pos_staff.update']);
    $uuid = DB::table('pos_staff')->where('id', p5Staff($ctx['branch'], 'Salma', 'cashier'))->value('uuid');

    $this->postJson("/api/pos-staff/{$uuid}/reset-pin")->assertForbidden();
    $this->patchJson("/api/pos-staff/{$uuid}", ['position' => 'manager'])->assertForbidden();
    expect(DB::table('pos_staff')->where('uuid', $uuid)->value('position'))->toBe('cashier');
    // The form re-sending the same position with other changes is fine.
    $this->patchJson("/api/pos-staff/{$uuid}", ['name' => 'Salma A.', 'position' => 'cashier'])->assertOk();

    $ctx2 = p5ActorWith(['pos_staff.view', 'pos_staff.update', 'pos_staff.change_position']);
    $uuid2 = DB::table('pos_staff')->where('id', p5Staff($ctx2['branch'], 'Omar', 'cashier'))->value('uuid');
    $this->patchJson("/api/pos-staff/{$uuid2}", ['position' => 'supervisor'])->assertOk()->assertJsonPath('data.position', 'supervisor');

    $ctx3 = p5ActorWith(['pos_staff.view', 'pos_staff.reset_pin']);
    $uuid3 = DB::table('pos_staff')->where('id', p5Staff($ctx3['branch'], 'Huda', 'cashier'))->value('uuid');
    config(['pos.approver_kdf_iterations' => 1000]);
    $this->postJson("/api/pos-staff/{$uuid3}/reset-pin")->assertOk()->assertJsonStructure(['plaintext_pin']);

    // The default Cashier Supervisor role (pos_staff.update) can do neither.
    $sup = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $uuid4 = DB::table('pos_staff')->where('id', p5Staff($sup['branch'], 'Ali', 'cashier'))->value('uuid');
    $this->postJson("/api/pos-staff/{$uuid4}/reset-pin")->assertForbidden();
    $this->patchJson("/api/pos-staff/{$uuid4}", ['position' => 'manager'])->assertForbidden();
});
