<?php

declare(strict_types=1);

/*
 * LAUNCH-P5 Part B test fixtures shared by the tests in this folder (required,
 * not a test file). Every helper is prefixed p5 so it never collides with the
 * suite's other global helpers. Fixtures write the tables directly (not the
 * models) so they mean the same whatever application code runs — the
 * fail-before run uses the launch-p4 head (8091213) with the P5 test schema.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

if (! function_exists('p5Fixture')) {
    /** A shared LAUNCH-P5 fixture (copied byte for byte from D:\launch-work\p5\shared). */
    function p5Fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/launch-p5/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A merchant user holding a custom role with exactly these permissions
     * (created when missing, so a permission new in P5 can be granted even
     * when the code under test does not know it yet).
     */
    function p5ActorWith(array $permissions, ?array $branchScope = null): array
    {
        $ctx = makeMerchantActor(MerchantRole::Viewer->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
        foreach ($permissions as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->create(['name' => 'Custom '.uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['company']->id]);
        $role->syncPermissions($permissions);
        $ctx['user']->syncRoles([$role]);
        if ($branchScope !== null) {
            $ctx['user']->forceFill(['branch_scope_json' => $branchScope])->save();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $ctx;
    }

    /** A second branch of the same company. */
    function p5Branch(Company $company, string $name): Branch
    {
        return Branch::factory()->for($company, 'company')->create(['name' => $name]);
    }

    /**
     * A staff row written straight to the table. Returns its id.
     *
     * @param  array<string, mixed>  $extra
     */
    function p5Staff(Branch $branch, string $name, string $position = 'cashier', array $extra = []): int
    {
        return (int) DB::table('pos_staff')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'name' => $name,
            'pin_hash' => Hash::make('123456'),
            'position' => $position,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** The decoded JSON value of a pos_company_settings row (null when absent). */
    function p5CompanySetting(int $companyId, string $key): mixed
    {
        $raw = DB::table('pos_company_settings')->where('company_id', $companyId)->where('key', $key)->value('value');

        return $raw === null ? null : json_decode((string) $raw, true);
    }

    /**
     * A pos_approvals row written straight to the table.
     *
     * @param  array<string, mixed>  $extra
     */
    function p5Approval(Branch $branch, string $action, string $result, array $extra = []): int
    {
        return (int) DB::table('pos_approvals')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'action' => $action,
            'mode' => 'approval',
            'method' => 'offline',
            'result' => $result,
            'approved_at' => now()->subHour(),
            'verified_at' => now()->subMinutes(50),
            'created_at' => now()->subMinutes(50),
        ], $extra));
    }

    /**
     * A paid (or other status) order written straight to the table.
     *
     * @param  array<string, mixed>  $extra
     */
    function p5Order(Branch $branch, array $extra = []): int
    {
        return (int) DB::table('pos_orders')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'order_type' => 'quick',
            'status' => 'paid',
            'source' => 'main_pos',
            'subtotal' => '5.000',
            'discount_total' => '0',
            'tax_total' => '0',
            'grand_total' => '5.000',
            'opened_at' => now()->subHour(),
            'closed_at' => now()->subHour(),
            'client_event_id' => 'evt_'.Str::random(16),
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }
}
