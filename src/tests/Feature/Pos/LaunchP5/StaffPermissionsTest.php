<?php

declare(strict_types=1);

/**
 * LAUNCH-P5 B1 — the staff permissions page (owner decision 1: a tick list
 * per position; anything not ticked needs a manager's approval; manual
 * discounts have a maximum % per position). It replaces Settings → Order
 * cancellation's four position lists, is gated by staff.permissions.manage,
 * audits every change, and keeps the four old lists in sync for old app
 * builds. Before: no page, no setting, no permission — only the four lists
 * behind orders.cancel.
 */

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use App\Models\Company;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** The fixture defaults in the resolved shape the page returns. */
function p5FixtureMatrix(): array
{
    $fixture = p5Fixture('position_permissions_defaults.json');
    $out = [];
    foreach ($fixture['positions'] as $position) {
        $out[$position] = [
            'actions' => $fixture['defaults'][$position]['actions'],
            'discount_max_percent' => $fixture['defaults'][$position]['discount_max_percent'],
        ];
    }

    return $out;
}

it('shows the shared fixture defaults when the company has no tick list yet', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);
    $fixture = p5Fixture('position_permissions_defaults.json');

    $data = $this->getJson('/api/settings/staff-permissions')->assertOk()->json('data');

    expect($data['positions'])->toBe($fixture['positions'])
        ->and($data['actions'])->toBe($fixture['actions'])
        ->and($data['permissions'])->toBe(p5FixtureMatrix())
        ->and($data['defaults'])->toBe(p5FixtureMatrix())
        ->and($data['always_on'])->toBe(['kitchen' => ['kitchen.screen']]);
    // Every position lists all 19 actions, in the fixture's order.
    foreach ($fixture['positions'] as $position) {
        expect(array_keys($data['permissions'][$position]['actions']))->toBe($fixture['actions']);
    }
});

it('resolves missing or malformed keys to the defaults and keeps the kitchen screen on for kitchen', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    DB::table('pos_company_settings')->insert([
        'company_id' => $ctx['company']->id,
        'key' => 'position_permissions',
        'value' => json_encode([
            'cashier' => ['actions' => ['comp' => true, 'payout' => 'yes', 'gift' => 1, 'not.an.action' => true]],
            'supervisor' => ['discount_max_percent' => 40],
            'waiter' => ['discount_max_percent' => 150],
            'kitchen' => ['actions' => ['kitchen.screen' => false]],
            'owner' => ['actions' => ['comp' => true]],
        ]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $matrix = $this->getJson('/api/settings/staff-permissions')->assertOk()->json('data.permissions');
    $expected = p5FixtureMatrix();
    $expected['cashier']['actions']['comp'] = true;            // a real boolean: used
    $expected['supervisor']['discount_max_percent'] = 40;       // in range: used
    // payout 'yes', gift 1, waiter 150 %, kitchen screen off, unknown keys: ignored.

    expect($matrix)->toBe($expected)
        ->and(array_keys($matrix))->toBe(['cashier', 'waiter', 'kitchen', 'supervisor', 'manager']);
});

it('saves ticks and limits as the full matrix and rewrites the four old lists from it', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $cid = $ctx['company']->id;
    // The old lists as the previous page left them.
    DB::table('pos_company_settings')->insert([
        ['company_id' => $cid, 'key' => 'order_cancel_positions', 'value' => json_encode(['cashier', 'manager']), 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $cid, 'key' => 'kitchen_positions', 'value' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $saved = $this->putJson('/api/settings/staff-permissions', ['permissions' => [
        'cashier' => ['actions' => ['comp' => true], 'discount_max_percent' => 15],
        'supervisor' => ['actions' => ['approvals.give' => true, 'reports.view' => true]],
        'waiter' => ['actions' => ['kitchen.screen' => true]],
    ]])->assertOk()->json('data.permissions');
    expect($saved['cashier']['actions']['comp'])->toBeTrue()
        ->and($saved['cashier']['discount_max_percent'])->toBe(15)
        ->and($saved['supervisor']['actions']['approvals.give'])->toBeTrue();

    $stored = p5CompanySetting($cid, 'position_permissions');
    $expected = p5FixtureMatrix();
    $expected['cashier']['actions']['comp'] = true;
    $expected['cashier']['discount_max_percent'] = 15;
    $expected['supervisor']['actions']['approvals.give'] = true;
    $expected['supervisor']['actions']['reports.view'] = true;
    $expected['waiter']['actions']['kitchen.screen'] = true;
    expect($stored)->toEqual($expected);

    // The four old keys, for old app builds.
    expect(p5CompanySetting($cid, 'manager_approval_positions'))->toBe(['supervisor', 'manager'])
        ->and(p5CompanySetting($cid, 'reports_positions'))->toBe(['supervisor', 'manager'])
        ->and(p5CompanySetting($cid, 'kitchen_positions'))->toBe(['waiter', 'manager'])   // kitchen is implicit
        ->and(p5CompanySetting($cid, 'order_cancel_positions'))->toBe(['manager']);        // from order.void_paid
});

it('audits only the cells that changed, and nothing when a save changes nothing', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $payload = ['permissions' => ['cashier' => ['actions' => ['loyalty.redeem' => true], 'discount_max_percent' => 20]]];

    $this->putJson('/api/settings/staff-permissions', $payload)->assertOk();
    $rows = DB::table('pos_audit_logs')->where('event', 'settings.position_permissions.updated')->get();
    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->actor_user_id)->toBe($ctx['user']->id)
        ->and((int) $rows[0]->company_id)->toBe($ctx['company']->id)
        ->and(json_decode($rows[0]->old_values, true))->toBe(['cashier.loyalty.redeem' => false, 'cashier.discount_max_percent' => 10])
        ->and(json_decode($rows[0]->new_values, true))->toBe(['cashier.loyalty.redeem' => true, 'cashier.discount_max_percent' => 20]);

    $this->putJson('/api/settings/staff-permissions', $payload)->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'settings.position_permissions.updated')->count())->toBe(1);
});

it('refuses unknown positions or actions, non-boolean ticks, bad limits, the kitchen screen off for kitchen, and no approver at all', function (array $permissions): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);

    $this->putJson('/api/settings/staff-permissions', ['permissions' => $permissions])->assertStatus(422);
    expect(p5CompanySetting($ctx['company']->id, 'position_permissions'))->toBeNull();
})->with([
    'unknown position' => [['owner' => ['actions' => ['comp' => true]]]],
    'unknown action' => [['cashier' => ['actions' => ['drawer.open' => true]]]],
    'non-boolean tick' => [['cashier' => ['actions' => ['comp' => 'yes']]]],
    'limit above 100' => [['cashier' => ['discount_max_percent' => 101]]],
    'negative limit' => [['cashier' => ['discount_max_percent' => -1]]],
    'fractional limit' => [['cashier' => ['discount_max_percent' => 12.5]]],
    'kitchen screen off for kitchen' => [['kitchen' => ['actions' => ['kitchen.screen' => false]]]],
    'nobody can approve' => [['manager' => ['actions' => ['approvals.give' => false]]]],
    'unknown field' => [['cashier' => ['colour' => 'red']]],
]);

it('is gated by staff.permissions.manage, not orders.cancel', function (): void {
    p5ActorWith(['orders.cancel']);
    $this->getJson('/api/settings/staff-permissions')->assertForbidden();
    $this->putJson('/api/settings/staff-permissions', ['permissions' => ['cashier' => ['discount_max_percent' => 5]]])->assertForbidden();

    p5ActorWith(['staff.permissions.manage']);
    $this->getJson('/api/settings/staff-permissions')->assertOk();
    $this->putJson('/api/settings/staff-permissions', ['permissions' => ['cashier' => ['discount_max_percent' => 5]]])->assertOk();

    makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $this->getJson('/api/settings/staff-permissions')->assertForbidden();
});

it('keeps each company to its own tick list', function (): void {
    $a = makeMerchantActor(MerchantRole::Manager->value);
    $this->putJson('/api/settings/staff-permissions', ['permissions' => ['waiter' => ['actions' => ['comp' => true]]]])->assertOk();

    $b = makeMerchantActor(MerchantRole::Manager->value);
    expect($this->getJson('/api/settings/staff-permissions')->assertOk()->json('data.permissions'))->toBe(p5FixtureMatrix())
        ->and(p5CompanySetting($b['company']->id, 'position_permissions'))->toBeNull()
        ->and(p5CompanySetting($a['company']->id, 'position_permissions')['waiter']['actions']['comp'])->toBeTrue();
});

it('retires the four old position-list endpoints (their lists now follow the tick list)', function (string $path): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);

    expect($this->putJson("/api/settings/{$path}", ['positions' => ['cashier', 'manager']])->status())->toBeIn([404, 405]);
    expect($this->getJson("/api/settings/{$path}")->status())->toBeIn([404, 405]);
    expect(DB::table('pos_company_settings')->where('company_id', $ctx['company']->id)->count())->toBe(0);
})->with(['order-cancellation', 'manager-approval', 'reports-positions', 'kitchen-positions']);

it('adds the four P5 portal permissions to the catalogue and to the default roles of a new company', function (): void {
    $keys = ['staff.permissions.manage', 'pos_staff.reset_pin', 'pos_staff.change_position', 'staff.attendance.manage'];
    expect(MerchantPermission::values())->toContain(...$keys)
        ->and(PermissionCatalog::allMerchantKeys())->toContain(...$keys);

    $company = Company::factory()->create();
    app(SeedMerchantRolesAction::class)->handle($company->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $perms = fn (string $role): array => Role::query()->where('team_id', $company->id)->where('name', $role)->sole()
        ->permissions->pluck('name')->all();

    expect($perms(MerchantRole::SuperAdmin->value))->toContain(...$keys)
        ->and($perms(MerchantRole::Manager->value))->toContain(...$keys);
    foreach ([MerchantRole::CashierSupervisor, MerchantRole::Viewer, MerchantRole::InventoryManager] as $role) {
        expect(array_intersect($perms($role->value), $keys))->toBe([]);
    }

    // The role builder labels them in both languages.
    makeMerchantActor();
    $group = collect($this->getJson('/api/roles/catalog')->assertOk()->json('data'))->firstWhere('key', 'pos_staff');
    $labels = collect($group['permissions'])->keyBy('key');
    foreach ($keys as $key) {
        expect($labels[$key]['label_en'] ?? '')->not->toBe('')
            ->and($labels[$key]['label_ar'] ?? '')->not->toBe('');
    }
});
