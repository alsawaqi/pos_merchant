<?php

declare(strict_types=1);

use App\Actions\Pos\Settings\SetBranchTableSessionsModeAction;
use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('reads branch-only off defaults without changing the existing round-mode response', function (): void {
    $ctx = makeMerchantActor();
    CompanySetting::query()->create([
        'company_id' => $ctx['company']->id, 'key' => 'table_sessions_mode', 'value' => 'live',
    ]);
    $old = $this->getJson('/api/settings/dine-in-round-mode')->assertOk()->json('data');
    expect(array_keys($old))->toBe(['company_default', 'company_default_editable', 'branches']);
    $read = $this->getJson('/api/settings/dine-in-round-mode?table_sessions=1')->assertOk()->json('data');
    expect($read)->toBe(['branches' => [['uuid' => $ctx['branch']->uuid, 'table_sessions_mode' => 'off']]]);
    expect(DB::table('pos_branch_settings')->count())->toBe(0);
});

it('stores each exact branch mode as JSON and audits once with no inheritance or company write', function (string $mode): void {
    $ctx = makeMerchantActor();
    $other = Branch::factory()->for($ctx['company'], 'company')->create();
    $url = "/api/settings/table-sessions-mode/branches/{$ctx['branch']->uuid}";
    $result = $this->putJson($url, ['mode' => $mode])->assertOk()->json('data');
    $rows = collect($result['branches'])->keyBy('uuid');
    expect($rows[$ctx['branch']->uuid]['table_sessions_mode'])->toBe($mode)
        ->and($rows[$other->uuid]['table_sessions_mode'])->toBe('off');
    $setting = DB::table('pos_branch_settings')->where('key', 'table_sessions_mode')->sole();
    expect((int) $setting->company_id)->toBe($ctx['company']->id)
        ->and((int) $setting->branch_id)->toBe($ctx['branch']->id)
        ->and($setting->value)->toBe(json_encode($mode))
        ->and(DB::table('pos_company_settings')->count())->toBe(0);
    $audit = DB::table('pos_audit_logs')->where('event', 'settings.table_sessions_mode.branch_updated')->sole();
    expect($audit->auditable_type)->toBe(Branch::class)
        ->and((int) $audit->auditable_id)->toBe($ctx['branch']->id)
        ->and((int) $audit->actor_user_id)->toBe($ctx['user']->id)
        ->and((int) $audit->company_id)->toBe($ctx['company']->id)
        ->and((int) $audit->branch_id)->toBe($ctx['branch']->id)
        ->and(json_decode($audit->old_values, true))->toBe(['table_sessions_mode' => null])
        ->and(json_decode($audit->new_values, true))->toBe(['table_sessions_mode' => $mode]);
    $this->putJson($url, ['mode' => $mode])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'settings.table_sessions_mode.branch_updated')->count())->toBe(1);
    expect($this->getJson('/api/settings/dine-in-round-mode?table_sessions=1')->assertOk()->json('data'))->toBe($result);
    if ($mode === 'shadow') {
        fwrite(STDOUT, "\nT5_MERCHANT_MODE_JSON=".json_encode($result, JSON_THROW_ON_ERROR)."\n");
        fwrite(STDOUT, 'T5_MERCHANT_AUDIT_JSON='.json_encode($audit, JSON_THROW_ON_ERROR)."\n");
    }
})->with(['off', 'shadow', 'live']);

it('rejects invalid or missing modes without any settings or audit writes', function (array $payload): void {
    $ctx = makeMerchantActor();
    $this->putJson("/api/settings/table-sessions-mode/branches/{$ctx['branch']->uuid}", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('mode');
    expect(DB::table('pos_branch_settings')->count())->toBe(0)
        ->and(DB::table('pos_company_settings')->count())->toBe(0)
        ->and(DB::table('pos_audit_logs')->where('event', 'settings.table_sessions_mode.branch_updated')->count())->toBe(0);
})->with([[['mode' => 'inherit']], [['mode' => 'SHADOW']], [['mode' => null]], [['mode' => true]], [[]]]);

it('refuses unprivileged writes and scopes both reads and writes to allowed tenant branches', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $this->putJson("/api/settings/table-sessions-mode/branches/{$ctx['branch']->uuid}", ['mode' => 'shadow'])->assertForbidden();
    $this->getJson('/api/settings/dine-in-round-mode?table_sessions=1')->assertOk();
    expect(DB::table('pos_branch_settings')->count())->toBe(0);
});

it('enforces branch and tenant boundaries and tolerates malformed rows as off', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $sibling = Branch::factory()->for($ctx['company'], 'company')->create();
    $foreign = Branch::factory()->for(Company::factory()->create(), 'company')->create();
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $this->putJson("/api/settings/table-sessions-mode/branches/{$sibling->uuid}", ['mode' => 'shadow'])->assertForbidden();
    $this->putJson("/api/settings/table-sessions-mode/branches/{$foreign->uuid}", ['mode' => 'shadow'])->assertNotFound();
    $setting = BranchSetting::query()->create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'key' => 'table_sessions_mode', 'value' => ['invalid'],
    ]);
    $this->getJson('/api/settings/dine-in-round-mode?table_sessions=1')->assertOk()
        ->assertJsonCount(1, 'data.branches')->assertJsonPath('data.branches.0.table_sessions_mode', 'off');
    $this->putJson("/api/settings/table-sessions-mode/branches/{$ctx['branch']->uuid}", ['mode' => 'shadow'])->assertOk();
    expect($setting->fresh()->value)->toBe('shadow');
    expect(fn () => app(SetBranchTableSessionsModeAction::class)->handle($ctx['branch'], 'inherit', $ctx['user']))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps the merchant table control permission gated and live enabled with paired translations', function (): void {
    expect(Route::getRoutes()->getByName('settings.table-sessions-mode.branch-update')->methods())->toBe(['PUT']);
    $page = file_get_contents(resource_path('js/Pages/Merchant/Settings/DineInRoundMode.vue'));
    expect($page)->toContain(':disabled="!canManage || tableSaving[branch.uuid]"', '<option value="live" :title=')
        ->toContain('@change="changeTableMode(branch, $event)"')
        ->not->toContain('<option value="live" disabled', "if (mode === 'live')");
    $en = json_decode(file_get_contents(resource_path('js/locales/en.json')), true, flags: JSON_THROW_ON_ERROR);
    $ar = json_decode(file_get_contents(resource_path('js/locales/ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = ['title', 'description', 'off', 'shadow', 'live', 'live_hint', 'saved'];
    expect(array_keys($en['settings']['table_sessions_mode']))->toBe($keys)
        ->and(array_keys($ar['settings']['table_sessions_mode']))->toBe($keys);
    foreach ($keys as $key) {
        expect($ar['settings']['table_sessions_mode'][$key])->not->toBeEmpty()
            ->not->toBe($en['settings']['table_sessions_mode'][$key]);
    }
});
