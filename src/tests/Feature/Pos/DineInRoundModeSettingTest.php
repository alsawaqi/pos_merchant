<?php

declare(strict_types=1);

use App\Actions\Pos\Settings\SetBranchDineInRoundModeAction;
use App\Actions\Pos\Settings\SetDineInRoundModeAction;
use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

it('defaults to direct handling and returns the exact alphabetically ordered branch contract', function (): void {
    $ctx = makeMerchantActor();
    $ctx['branch']->forceFill(['name' => 'Main', 'name_ar' => null, 'code' => 'MAIN'])->save();
    $other = Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Airport']);
    Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Retired'])->delete();

    $data = $this->getJson('/api/settings/dine-in-round-mode')->assertOk()->json('data');

    expect($data)->toBe([
        'company_default' => 'kitchen_direct',
        'company_default_editable' => true,
        'branches' => [
            ['uuid' => $other->uuid, 'name' => 'Airport', 'name_ar' => null, 'code' => $other->code, 'mode' => null, 'effective' => 'kitchen_direct'],
            ['uuid' => $ctx['branch']->uuid, 'name' => 'Main', 'name_ar' => null, 'code' => 'MAIN', 'mode' => null, 'effective' => 'kitchen_direct'],
        ],
    ]);
});

it('stores a JSON scalar company default once and updates every inheriting branch both ways', function (): void {
    $ctx = makeMerchantActor();
    Branch::factory()->for($ctx['company'], 'company')->create();

    foreach (['staff_confirm', 'kitchen_direct'] as $mode) {
        $saved = $this->putJson('/api/settings/dine-in-round-mode', ['mode' => $mode])->assertOk()->json('data');
        $read = $this->getJson('/api/settings/dine-in-round-mode')->assertOk()->json('data');
        expect($read)->toBe($saved)
            ->and($read['company_default'])->toBe($mode)
            ->and($read['company_default_editable'])->toBeTrue()
            ->and($read['branches'])->toHaveCount(2);
        foreach ($read['branches'] as $branch) {
            expect($branch['mode'])->toBeNull()->and($branch['effective'])->toBe($mode);
        }
        expect(DB::table('pos_company_settings')->where('key', 'dine_in_round_mode')->count())->toBe(1)
            ->and(DB::table('pos_company_settings')->where('key', 'dine_in_round_mode')->value('value'))->toBe(json_encode($mode));
    }
    expect(DB::table('pos_branch_settings')->count())->toBe(0);
});

it('stores a tenant-owned branch override and deletes it to inherit the current company default', function (): void {
    $ctx = makeMerchantActor();
    $url = "/api/settings/dine-in-round-mode/branches/{$ctx['branch']->uuid}";

    $saved = $this->putJson($url, ['mode' => 'staff_confirm'])->assertOk()->json('data');
    expect($saved['branches'][0]['mode'])->toBe('staff_confirm')
        ->and($saved['branches'][0]['effective'])->toBe('staff_confirm');
    $setting = BranchSetting::query()->sole();
    expect((int) $setting->company_id)->toBe($ctx['company']->id)
        ->and($setting->value)->toBe('staff_confirm')
        ->and($setting->branch->is($ctx['branch']))->toBeTrue()
        ->and(DB::table('pos_branch_settings')->value('value'))->toBe('"staff_confirm"');
    expect($this->getJson('/api/settings/dine-in-round-mode')->assertOk()->json('data'))->toBe($saved);

    $inherited = $this->putJson($url, ['mode' => 'inherit'])->assertOk()->json('data');
    expect(DB::table('pos_branch_settings')->count())->toBe(0)
        ->and($inherited['branches'][0]['mode'])->toBeNull()
        ->and($inherited['branches'][0]['effective'])->toBe('kitchen_direct');

    $this->putJson('/api/settings/dine-in-round-mode', ['mode' => 'staff_confirm'])->assertOk();
    $this->putJson($url, ['mode' => 'kitchen_direct'])->assertOk();
    $inherited = $this->putJson($url, ['mode' => 'inherit'])->assertOk()->json('data');
    expect($inherited['branches'][0]['effective'])->toBe('staff_confirm')
        ->and(DB::table('pos_branch_settings')->count())->toBe(0);
});

it('tolerates a garbage company value and audits a corrected value from null', function (string $raw): void {
    $ctx = makeMerchantActor();
    DB::table('pos_company_settings')->insert([
        'company_id' => $ctx['company']->id, 'key' => 'dine_in_round_mode', 'value' => $raw,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson('/api/settings/dine-in-round-mode')->assertOk()->assertJsonPath('data.company_default', 'kitchen_direct');
    $this->putJson('/api/settings/dine-in-round-mode', ['mode' => 'staff_confirm'])
        ->assertOk()->assertJsonPath('data.company_default', 'staff_confirm');
    $audit = DB::table('pos_audit_logs')->where('event', 'settings.dine_in_round_mode.updated')->sole();
    expect(json_decode($audit->old_values, true))->toBe(['dine_in_round_mode' => null])
        ->and(json_decode($audit->new_values, true))->toBe(['dine_in_round_mode' => 'staff_confirm']);
})->with([
    'malformed JSON' => ['{not-json'],
    'JSON array' => ['["staff_confirm"]'],
    'unknown mode' => ['"unknown-mode"'],
    'JSON null' => ['null'],
]);

it('renders a garbage branch value as inherit and removes that row when inherit is saved', function (string $raw): void {
    $ctx = makeMerchantActor();
    CompanySetting::query()->create(['company_id' => $ctx['company']->id, 'key' => 'dine_in_round_mode', 'value' => 'staff_confirm']);
    DB::table('pos_branch_settings')->insert([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'key' => 'dine_in_round_mode', 'value' => $raw, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson('/api/settings/dine-in-round-mode')->assertOk()
        ->assertJsonPath('data.company_default', 'staff_confirm')
        ->assertJsonPath('data.branches.0.mode', null)
        ->assertJsonPath('data.branches.0.effective', 'staff_confirm');
    $this->putJson("/api/settings/dine-in-round-mode/branches/{$ctx['branch']->uuid}", ['mode' => 'inherit'])
        ->assertOk()->assertJsonPath('data.branches.0.effective', 'staff_confirm');
    expect(DB::table('pos_branch_settings')->count())->toBe(0);
})->with([
    'malformed JSON' => ['{not-json'],
    'JSON array' => ['["staff_confirm"]'],
    'unknown mode' => ['"unknown-mode"'],
    'JSON null' => ['null'],
]);

it('rejects invalid or missing modes without writing settings', function (bool $branch, array $payload): void {
    $ctx = makeMerchantActor();
    $url = '/api/settings/dine-in-round-mode'.($branch ? "/branches/{$ctx['branch']->uuid}" : '');
    $this->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('mode');
    expect(DB::table('pos_company_settings')->count())->toBe(0)
        ->and(DB::table('pos_branch_settings')->count())->toBe(0);
})->with([
    'unknown company mode' => [false, ['mode' => 'automatic']],
    'unknown branch mode' => [true, ['mode' => 'automatic']],
    'company cannot inherit' => [false, ['mode' => 'inherit']],
    'missing company mode' => [false, []],
    'missing branch mode' => [true, []],
    'null company mode' => [false, ['mode' => null]],
    'null branch mode' => [true, ['mode' => null]],
    'non-string branch mode' => [true, ['mode' => true]],
]);

it('enforces the default role matrix on all three endpoints', function (string $role, bool $editable): void {
    $ctx = makeMerchantActor($role);
    $this->getJson('/api/settings/dine-in-round-mode')->assertOk()
        ->assertJsonPath('data.company_default_editable', $editable);
    $company = $this->putJson('/api/settings/dine-in-round-mode', ['mode' => 'staff_confirm']);
    $branch = $this->putJson("/api/settings/dine-in-round-mode/branches/{$ctx['branch']->uuid}", ['mode' => 'staff_confirm']);
    $company->assertStatus($editable ? 200 : 403);
    $branch->assertStatus($editable ? 200 : 403);
})->with([
    'SuperAdmin' => [MerchantRole::SuperAdmin->value, true],
    'Manager' => [MerchantRole::Manager->value, true],
    'InventoryManager' => [MerchantRole::InventoryManager->value, false],
    'CashierSupervisor' => [MerchantRole::CashierSupervisor->value, false],
    'Viewer' => [MerchantRole::Viewer->value, false],
]);

it('returns 403 on reads and both writes without their permissions', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson('/api/settings/dine-in-round-mode')->assertForbidden();
    $this->putJson('/api/settings/dine-in-round-mode', ['mode' => 'staff_confirm'])->assertForbidden();
    $this->putJson("/api/settings/dine-in-round-mode/branches/{$ctx['branch']->uuid}", ['mode' => 'staff_confirm'])->assertForbidden();
    expect(DB::table('pos_branch_settings')->count())->toBe(0)
        ->and(DB::table('pos_company_settings')->count())->toBe(0);
});

it('hides foreign settings and returns 404 for a foreign branch even for a scoped actor', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $foreignCompany = Company::factory()->create();
    $foreign = Branch::factory()->for($foreignCompany, 'company')->create();
    CompanySetting::query()->create(['company_id' => $foreignCompany->id, 'key' => 'dine_in_round_mode', 'value' => 'staff_confirm']);
    BranchSetting::query()->create(['company_id' => $foreignCompany->id, 'branch_id' => $foreign->id, 'key' => 'dine_in_round_mode', 'value' => 'staff_confirm']);

    $this->getJson('/api/settings/dine-in-round-mode')->assertOk()
        ->assertJsonPath('data.company_default', 'kitchen_direct')
        ->assertJsonCount(1, 'data.branches')
        ->assertJsonPath('data.branches.0.uuid', $ctx['branch']->uuid);
    $this->putJson("/api/settings/dine-in-round-mode/branches/{$foreign->uuid}", ['mode' => 'staff_confirm'])->assertNotFound();
});

it('restricts a Manager to allowed branches and forbids changing the company default', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();

    $this->getJson('/api/settings/dine-in-round-mode')->assertOk()
        ->assertJsonPath('data.company_default_editable', false)
        ->assertJsonCount(1, 'data.branches')
        ->assertJsonPath('data.branches.0.uuid', $ctx['branch']->uuid);
    $this->putJson("/api/settings/dine-in-round-mode/branches/{$branchB->uuid}", ['mode' => 'staff_confirm'])->assertForbidden();
    $this->putJson('/api/settings/dine-in-round-mode', ['mode' => 'staff_confirm'])->assertForbidden();
    $this->putJson("/api/settings/dine-in-round-mode/branches/{$ctx['branch']->uuid}", ['mode' => 'staff_confirm'])->assertOk()
        ->assertJsonPath('data.company_default_editable', false)
        ->assertJsonCount(1, 'data.branches')
        ->assertJsonPath('data.branches.0.effective', 'staff_confirm');
    expect(DB::table('pos_company_settings')->count())->toBe(0);
});

it('audits changes and inheritance against stable subjects but writes nothing on no-op saves', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 5)->setTime(12, 0));
    $ctx = makeMerchantActor();
    $url = "/api/settings/dine-in-round-mode/branches/{$ctx['branch']->uuid}";

    $this->putJson($url, ['mode' => 'inherit'])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'like', 'settings.dine_in_round_mode.%')->count())->toBe(0);
    $this->putJson('/api/settings/dine-in-round-mode', ['mode' => 'staff_confirm'])->assertOk();
    $this->putJson($url, ['mode' => 'kitchen_direct'])->assertOk();
    $companyRow = DB::table('pos_company_settings')->sole();
    $branchRow = DB::table('pos_branch_settings')->sole();
    $this->travel(1)->minutes();
    $this->putJson('/api/settings/dine-in-round-mode', ['mode' => 'staff_confirm'])->assertOk();
    $this->putJson($url, ['mode' => 'kitchen_direct'])->assertOk();

    $companyAudit = DB::table('pos_audit_logs')->where('event', 'settings.dine_in_round_mode.updated')->sole();
    $branchAudit = DB::table('pos_audit_logs')->where('event', 'settings.dine_in_round_mode.branch_updated')->sole();
    expect($companyAudit->auditable_type)->toBe(CompanySetting::class)
        ->and((int) $companyAudit->auditable_id)->toBe((int) $companyRow->id)
        ->and((int) $companyAudit->actor_user_id)->toBe($ctx['user']->id)
        ->and((int) $companyAudit->company_id)->toBe($ctx['company']->id)
        ->and(json_decode($companyAudit->old_values, true))->toBe(['dine_in_round_mode' => null])
        ->and(json_decode($companyAudit->new_values, true))->toBe(['dine_in_round_mode' => 'staff_confirm'])
        ->and($branchAudit->auditable_type)->toBe(Branch::class)
        ->and((int) $branchAudit->auditable_id)->toBe($ctx['branch']->id)
        ->and((int) $branchAudit->branch_id)->toBe($ctx['branch']->id)
        ->and(json_decode($branchAudit->old_values, true))->toBe(['dine_in_round_mode' => null])
        ->and(json_decode($branchAudit->new_values, true))->toBe(['dine_in_round_mode' => 'kitchen_direct'])
        ->and(DB::table('pos_company_settings')->sole()->updated_at)->toBe($companyRow->updated_at)
        ->and(DB::table('pos_branch_settings')->sole()->updated_at)->toBe($branchRow->updated_at);

    $this->putJson($url, ['mode' => 'inherit'])->assertOk();
    $deletedAudit = DB::table('pos_audit_logs')->where('event', 'settings.dine_in_round_mode.branch_updated')->orderByDesc('id')->first();
    expect($deletedAudit->auditable_type)->toBe(Branch::class)
        ->and((int) $deletedAudit->auditable_id)->toBe($ctx['branch']->id)
        ->and(json_decode($deletedAudit->old_values, true))->toBe(['dine_in_round_mode' => 'kitchen_direct'])
        ->and(json_decode($deletedAudit->new_values, true))->toBe(['dine_in_round_mode' => null])
        ->and(DB::table('pos_branch_settings')->count())->toBe(0);
    $this->putJson($url, ['mode' => 'inherit'])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'settings.dine_in_round_mode.branch_updated')->count())->toBe(2);
});

it('guards direct action callers against invalid modes and foreign branch ownership', function (): void {
    $ctx = makeMerchantActor();
    $foreign = Branch::factory()->for(Company::factory()->create(), 'company')->create();

    expect(fn () => app(SetDineInRoundModeAction::class)->handle('inherit', $ctx['user']))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(SetBranchDineInRoundModeAction::class)->handle($ctx['branch'], 'automatic', $ctx['user']))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(SetBranchDineInRoundModeAction::class)->handle($foreign, null, $ctx['user']))->toThrow(NotFoundHttpException::class);
    expect(DB::table('pos_branch_settings')->count())->toBe(0);
});

it('registers the three named routes and keeps the page controls independently permission gated', function (): void {
    expect(Route::getRoutes()->getByName('settings.dine-in-round-mode.show')->methods())->toBe(['GET', 'HEAD'])
        ->and(Route::getRoutes()->getByName('settings.dine-in-round-mode.update')->methods())->toBe(['PUT'])
        ->and(Route::getRoutes()->getByName('settings.dine-in-round-mode.branch-update')->uri())->toBe('api/settings/dine-in-round-mode/branches/{branch}');

    $page = file_get_contents(resource_path('js/Pages/Merchant/Settings/DineInRoundMode.vue'));
    $navigation = file_get_contents(resource_path('js/Layouts/MerchantLayout.vue'));
    expect($page)->toContain('can(MerchantPermission.BranchesUpdate)', '@change="changeDefault"', '@change="changeBranch(branch, $event)"')
        ->toContain('!setting.company_default_editable', 'branchSaving[branch.uuid]', 'branchErrors[branch.uuid]', 'e.status === 403')
        ->not->toContain('<button')
        ->and($navigation)->toContain("{ key: 'dine_in_round_mode', to: '/settings/dine-in-round-mode', icon: ChefHat, permission: MerchantPermission.BranchesView }");
});

it('provides translated labels with the same two mode names as the admin portal', function (): void {
    $en = json_decode(file_get_contents(resource_path('js/locales/en.json')), true, flags: JSON_THROW_ON_ERROR);
    $ar = json_decode(file_get_contents(resource_path('js/locales/ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = ['title', 'subtitle', 'company_default_label', 'branch_column', 'mode_column', 'effective_column', 'inherit', 'kitchen_direct', 'staff_confirm', 'forbidden', 'save_success', 'save_failed'];
    expect(array_keys($en['settings']['dine_in_round_mode']))->toBe($keys)
        ->and(array_keys($ar['settings']['dine_in_round_mode']))->toBe($keys);
    foreach ($keys as $key) {
        expect($ar['settings']['dine_in_round_mode'][$key])->not->toBeEmpty()->not->toBe($en['settings']['dine_in_round_mode'][$key]);
    }
    expect($ar['nav']['dine_in_round_mode'])->not->toBe($en['nav']['dine_in_round_mode'])
        ->and($en['settings']['dine_in_round_mode']['kitchen_direct'])->toBe('Send directly to kitchen')
        ->and($en['settings']['dine_in_round_mode']['staff_confirm'])->toBe('Require staff confirmation')
        ->and($ar['settings']['dine_in_round_mode']['kitchen_direct'])->toBe('إرسال مباشر إلى المطبخ')
        ->and($ar['settings']['dine_in_round_mode']['staff_confirm'])->toBe('طلب تأكيد الموظف');
});
