<?php

declare(strict_types=1);

use App\Actions\Pos\Settings\SetBranchQrTableCardSettingsAction;
use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('reads off advisory card defaults without writing settings and reports exact fence presence', function (): void {
    $ctx = makeMerchantActor();
    config()->set('qr.web_base_url', '');
    $expected = [
        'uuid' => $ctx['branch']->uuid, 'name' => $ctx['branch']->name, 'name_ar' => null,
        'card_enabled' => 'off', 'geofence_mode' => 'advisory', 'fenced' => false,
    ];
    $this->getJson('/api/settings/qr-table-cards')->assertOk()
        ->assertExactJson(['data' => ['web_base_url_configured' => false, 'branches' => [$expected]]]);
    expect(DB::table('pos_branch_settings')->count())->toBe(0);
    $ctx['branch']->forceFill(['latitude' => 0, 'longitude' => 0])->save();
    config()->set('qr.web_base_url', 'https://qr.example.invalid');
    $this->getJson('/api/settings/qr-table-cards')->assertOk()
        ->assertJsonPath('data.web_base_url_configured', true)->assertJsonPath('data.branches.0.fenced', true);
});

it('stores both card settings as JSON and writes one complete audit with a no-op rerun', function (string $enabled, string $mode): void {
    $ctx = makeMerchantActor();
    config()->set('qr.web_base_url', 'https://qr.example.invalid');
    $url = "/api/settings/qr-table-cards/branches/{$ctx['branch']->uuid}";
    $result = $this->putJson($url, ['card_enabled' => $enabled, 'geofence_mode' => $mode])->assertOk()->json('data');
    $rows = DB::table('pos_branch_settings')->orderBy('key')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('value', 'key')->all())->toBe([
            'qr_scan_geofence_mode' => json_encode($mode), 'qr_table_card_enabled' => json_encode($enabled),
        ]);
    foreach ($rows as $row) {
        expect((int) $row->company_id)->toBe($ctx['company']->id)
            ->and((int) $row->branch_id)->toBe($ctx['branch']->id);
    }
    $audit = DB::table('pos_audit_logs')->where('event', 'settings.qr_table_cards.branch_updated')->sole();
    expect($audit->auditable_type)->toBe(Branch::class)
        ->and((int) $audit->auditable_id)->toBe($ctx['branch']->id)
        ->and((int) $audit->actor_user_id)->toBe($ctx['user']->id)
        ->and(json_decode($audit->old_values, true))->toBe(['qr_table_card_enabled' => null, 'qr_scan_geofence_mode' => null])
        ->and(json_decode($audit->new_values, true))->toBe(['qr_table_card_enabled' => $enabled, 'qr_scan_geofence_mode' => $mode]);
    $this->putJson($url, ['card_enabled' => $enabled, 'geofence_mode' => $mode])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'settings.qr_table_cards.branch_updated')->count())->toBe(1)
        ->and(DB::table('pos_company_settings')->count())->toBe(0);
    expect($this->getJson('/api/settings/qr-table-cards')->assertOk()->json('data'))->toBe($result);
    if ($enabled === 'on' && $mode === 'advisory') {
        fwrite(STDOUT, "\nT9_MERCHANT_SETTINGS=".json_encode($result, JSON_THROW_ON_ERROR)."\n");
        fwrite(STDOUT, 'T9_MERCHANT_ROWS='.json_encode($rows, JSON_THROW_ON_ERROR)."\n");
        fwrite(STDOUT, 'T9_MERCHANT_AUDIT='.json_encode($audit, JSON_THROW_ON_ERROR)."\n");
    }
})->with([['off', 'off'], ['on', 'advisory'], ['on', 'enforce']]);

it('rejects invalid and missing card switches without settings or audit writes', function (array $payload): void {
    $ctx = makeMerchantActor();
    $this->putJson("/api/settings/qr-table-cards/branches/{$ctx['branch']->uuid}", $payload)->assertUnprocessable();
    expect(DB::table('pos_branch_settings')->count())->toBe(0)
        ->and(DB::table('pos_audit_logs')->where('event', 'settings.qr_table_cards.branch_updated')->count())->toBe(0);
})->with([[[]], [['card_enabled' => 'on']], [['geofence_mode' => 'advisory']], [['card_enabled' => true, 'geofence_mode' => 'advisory']], [['card_enabled' => 'on', 'geofence_mode' => 'ENFORCE']]]);

it('gates card writes by permission and scopes reads and writes by branch and tenant', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $this->getJson('/api/settings/qr-table-cards')->assertOk();
    $this->putJson("/api/settings/qr-table-cards/branches/{$ctx['branch']->uuid}", ['card_enabled' => 'on', 'geofence_mode' => 'advisory'])->assertForbidden();
    expect(DB::table('pos_branch_settings')->count())->toBe(0);
    $ctx['user']->syncRoles([MerchantRole::Manager->value]);
    $sibling = Branch::factory()->for($ctx['company'], 'company')->create();
    $foreign = Branch::factory()->for(Company::factory()->create(), 'company')->create();
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $this->getJson('/api/settings/qr-table-cards')->assertOk()->assertJsonCount(1, 'data.branches');
    $this->putJson("/api/settings/qr-table-cards/branches/{$sibling->uuid}", ['card_enabled' => 'on', 'geofence_mode' => 'off'])->assertForbidden();
    $this->putJson("/api/settings/qr-table-cards/branches/{$foreign->uuid}", ['card_enabled' => 'on', 'geofence_mode' => 'off'])->assertNotFound();
});

it('reads malformed settings as defaults and fixes only the branch rows on an explicit save', function (): void {
    $ctx = makeMerchantActor();
    foreach (['qr_table_card_enabled', 'qr_scan_geofence_mode'] as $key) {
        BranchSetting::query()->create(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'key' => $key, 'value' => ['invalid']]);
    }
    $this->getJson('/api/settings/qr-table-cards')->assertOk()
        ->assertJsonPath('data.branches.0.card_enabled', 'off')->assertJsonPath('data.branches.0.geofence_mode', 'advisory');
    $this->putJson("/api/settings/qr-table-cards/branches/{$ctx['branch']->uuid}", ['card_enabled' => 'off', 'geofence_mode' => 'advisory'])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'settings.qr_table_cards.branch_updated')->count())->toBe(1);
    expect(fn () => app(SetBranchQrTableCardSettingsAction::class)->handle($ctx['branch'], 'yes', 'off', $ctx['user']))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps card controls permission gated with matching nonempty English and Arabic keys', function (): void {
    expect(Route::getRoutes()->getByName('settings.qr-table-cards.branch-update')->methods())->toBe(['PUT']);
    $page = file_get_contents(resource_path('js/Pages/Merchant/Settings/QrTableCards.vue'));
    expect($page)->toContain(':disabled="!canManage || saving[branch.uuid]"', 'canPrint && setting.web_base_url_configured', 'branch.fenced');
    $en = json_decode(file_get_contents(resource_path('js/locales/en.json')), true, flags: JSON_THROW_ON_ERROR);
    $ar = json_decode(file_get_contents(resource_path('js/locales/ar.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ([[$en['settings']['qr_table_cards'], $ar['settings']['qr_table_cards']], [$en['print']['table_cards'], $ar['print']['table_cards']]] as [$english, $arabic]) {
        expect(array_keys($arabic))->toBe(array_keys($english));
        foreach ($english as $key => $value) {
            expect($arabic[$key])->not->toBeEmpty()->not->toBe($value);
        }
    }
    expect($en['nav']['qr_table_cards'])->toBe('QR table cards')
        ->and($ar['nav']['qr_table_cards'])->not->toBeEmpty();
});
