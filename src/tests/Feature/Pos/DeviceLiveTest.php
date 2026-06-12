<?php

declare(strict_types=1);

/**
 * P-G9 — the merchant's restricted device Live (scalefusion MDM)
 * surface.
 *
 * Telemetry view (devices.live.view) + EXACTLY four safe commands
 * (devices.live.control: restart / shutdown / message / beep). The
 * whitelist is asserted structurally: the sharp verbs have no route,
 * and the slim client encodes shutdown's action_type itself so no
 * request shape can smuggle factory_reset through. Tenant 404 before
 * F5 scope 403 (probed with a branch-RESTRICTED actor, where ordering
 * is observable), kiosk-id 422, audit rows on success AND failure,
 * and the failure-status relay (incl. the upstream-401 clamp) are all
 * covered alongside the permission matrix.
 */

use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Point the client at a fake host so a missing Http::fake pattern
    // can never reach the real API; the phpunit.xml token is blank, so
    // tests that exercise the configured path set one explicitly here.
    config([
        'services.scalefusion.token' => 'test-token',
        'services.scalefusion.base_v3' => 'https://scalefusion.test/api/v3',
        'services.scalefusion.base_v1' => 'https://scalefusion.test/api/v1',
    ]);
});

/** An admin-provisioned device row at [$branch] (Device has no factory). */
function seedLiveDevice(array $ctx, ?Branch $branch = null, array $overrides = []): Device
{
    $branch ??= $ctx['branch'];

    $id = DB::table('pos_devices')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'branch_id' => $branch->id,
        'name' => 'Till 1',
        'device_type' => 'cashier',
        'status' => 'active',
        'kiosk_id' => '7001',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return Device::query()->findOrFail($id);
}

it('relays the v3 live telemetry for an own device', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx);

    Http::fake([
        'scalefusion.test/api/v3/devices/7001.json' => Http::response([
            'device' => ['id' => 7001, 'name' => 'Till 1', 'battery_status' => 88, 'cpu_usage' => 12],
        ], 200),
    ]);

    $res = $this->getJson("/api/devices/{$device->uuid}/live")->assertOk();
    expect($res->json('data.device.battery_status'))->toBe(88);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token test-token')
        && str_contains($request->url(), '/api/v3/devices/7001.json'));
});

it('reboots via the v1 PUT and audits the command', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx);

    Http::fake(['scalefusion.test/api/v1/devices/7001/reboot.json' => Http::response(['status' => 'ok'], 200)]);

    $this->postJson("/api/devices/{$device->uuid}/live/reboot")
        ->assertOk()
        ->assertJsonPath('ok', true);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v1/devices/7001/reboot.json'));

    $log = AuditLog::query()->where('event', 'device.scalefusion.reboot')->firstOrFail();
    expect($log->company_id)->toBe($ctx['company']->id)
        ->and($log->branch_id)->toBe($ctx['branch']->id)
        ->and($log->actor_user_id)->toBe($ctx['user']->id)
        ->and($log->auditable_id)->toBe($device->id)
        ->and($log->metadata['kiosk_id'])->toBe('7001')
        ->and($log->metadata['ok'])->toBeTrue()
        ->and($log->metadata['origin'])->toBe('merchant_portal');
});

it('shuts down through the actions endpoint with the action_type hard-coded', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx);

    Http::fake(['scalefusion.test/api/v1/devices/actions.json*' => Http::response(['status' => 'ok'], 200)]);

    // A hostile body trying to smuggle a sharper action is ignored —
    // the client encodes action_type itself.
    $this->postJson("/api/devices/{$device->uuid}/live/shutdown", ['action_type' => 'factory_reset'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'device_ids%5B%5D=7001')
        && str_contains($request->body(), 'action_type=shutdown')
        && ! str_contains($request->body(), 'factory_reset')
        && str_contains($request->body(), 'wipe_sd_card=false'));

    expect(AuditLog::query()->where('event', 'device.scalefusion.action:shutdown')->exists())->toBeTrue();
});

it('beeps via send_alarm', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx);

    Http::fake(['scalefusion.test/api/v1/devices/7001/send_alarm.json' => Http::response(['status' => 'ok'], 200)]);

    $this->postJson("/api/devices/{$device->uuid}/live/alarm")->assertOk()->assertJsonPath('ok', true);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v1/devices/7001/send_alarm.json'));
    expect(AuditLog::query()->where('event', 'device.scalefusion.alarm')->exists())->toBeTrue();
});

it('broadcasts an on-screen message form-encoded and validates the body', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx);

    Http::fake(['scalefusion.test/api/v1/devices/broadcast_message.json' => Http::response(['status' => 'ok'], 200)]);

    // Validation first.
    $this->postJson("/api/devices/{$device->uuid}/live/message", ['sender_name' => 'HQ'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message_body']);

    $this->postJson("/api/devices/{$device->uuid}/live/message", [
        'sender_name' => 'HQ Ops',
        'message_body' => 'Close early today',
    ])->assertOk()->assertJsonPath('ok', true);

    Http::assertSent(fn ($request) => str_contains($request->body(), 'device_ids%5B%5D=7001')
        && str_contains($request->body(), 'sender_name=HQ%20Ops')
        && str_contains($request->body(), 'message_body=Close%20early%20today')
        && str_contains($request->body(), 'keep_ringing=true')
        && str_contains($request->body(), 'show_as_dialog=true'));

    // MIXED explicit flags wire through individually — catches both a
    // broken boolean coercion and swapped keepRinging/showAsDialog args
    // (identical defaults would mask a swap on the case above).
    $this->postJson("/api/devices/{$device->uuid}/live/message", [
        'sender_name' => 'HQ Ops',
        'message_body' => 'quiet one',
        'keep_ringing' => false,
        'show_as_dialog' => true,
    ])->assertOk();
    Http::assertSent(fn ($request) => str_contains($request->body(), 'message_body=quiet%20one')
        && str_contains($request->body(), 'keep_ringing=false')
        && str_contains($request->body(), 'show_as_dialog=true'));

    // Length boundaries (101 / 1001 chars).
    $this->postJson("/api/devices/{$device->uuid}/live/message", [
        'sender_name' => str_repeat('a', 101),
        'message_body' => 'x',
    ])->assertUnprocessable()->assertJsonValidationErrors(['sender_name']);
    $this->postJson("/api/devices/{$device->uuid}/live/message", [
        'sender_name' => 'HQ',
        'message_body' => str_repeat('x', 1001),
    ])->assertUnprocessable()->assertJsonValidationErrors(['message_body']);

    expect(AuditLog::query()->where('event', 'device.scalefusion.broadcast_message')->exists())->toBeTrue();
});

it('exposes NO route for the admin-only sharp verbs', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx);

    Http::fake();

    foreach (['lock', 'unlock', 'wipe', 'action', 'clear-app-data', 'mark-lost'] as $verb) {
        $this->postJson("/api/devices/{$device->uuid}/live/{$verb}")->assertNotFound();
    }

    Http::assertNothingSent();
});

it('422s a device with no kiosk id before any HTTP call', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx, null, ['kiosk_id' => '']);

    Http::fake();

    $this->getJson("/api/devices/{$device->uuid}/live")->assertUnprocessable();
    $this->postJson("/api/devices/{$device->uuid}/live/reboot")->assertUnprocessable();

    Http::assertNothingSent();
    expect(AuditLog::query()->where('event', 'like', 'device.scalefusion.%')->exists())->toBeFalse();
});

it('404s a foreign tenant device without revealing it exists', function (): void {
    // A branch-RESTRICTED Manager: a SuperAdmin passes the scope check
    // vacuously, so only this actor proves the tenant 404 fires BEFORE
    // the scope 403 (swapping the two guards would answer 403 here).
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();

    $other = Company::factory()->create();
    $otherBranch = Branch::factory()->for($other, 'company')->create();
    $foreign = seedLiveDevice(['company' => $other, 'branch' => $otherBranch]);

    Http::fake();

    $this->getJson("/api/devices/{$foreign->uuid}/live")->assertNotFound();
    $this->postJson("/api/devices/{$foreign->uuid}/live/reboot")->assertNotFound();
    $this->postJson("/api/devices/{$foreign->uuid}/live/shutdown")->assertNotFound();
    // message must 404 even on an INVALID body — a 422 here would be a
    // foreign-uuid existence oracle (guards run before validation).
    $this->postJson("/api/devices/{$foreign->uuid}/live/message", [])->assertNotFound();
    Http::assertNothingSent();
});

it('enforces the F5 branch scope: out-of-scope 403, in-scope allowed, NULL branch HQ-only', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $otherBranch = Branch::factory()->for($ctx['company'], 'company')->create();
    $onMyBranch = seedLiveDevice($ctx);
    $onOtherBranch = seedLiveDevice($ctx, $otherBranch, ['kiosk_id' => '7002']);
    $unassigned = seedLiveDevice($ctx, null, ['branch_id' => null, 'kiosk_id' => '7003']);

    Http::fake(['scalefusion.test/*' => Http::response(['device' => []], 200)]);

    // Restricted to my branch only.
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();

    $this->getJson("/api/devices/{$onMyBranch->uuid}/live")->assertOk();
    $this->getJson("/api/devices/{$onOtherBranch->uuid}/live")->assertForbidden();
    $this->postJson("/api/devices/{$onOtherBranch->uuid}/live/reboot")->assertForbidden();
    // Unassigned device = company-central = HQ-only.
    $this->getJson("/api/devices/{$unassigned->uuid}/live")->assertForbidden();

    // Unrestricted again: everything reachable.
    $ctx['user']->forceFill(['branch_scope_json' => null])->save();
    $this->getJson("/api/devices/{$onOtherBranch->uuid}/live")->assertOk();
    $this->getJson("/api/devices/{$unassigned->uuid}/live")->assertOk();
});

it('gates the surface: Viewer nothing, view key = telemetry only, Manager everything', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $device = seedLiveDevice($ctx);

    Http::fake(['scalefusion.test/*' => Http::response(['device' => []], 200)]);

    $this->getJson("/api/devices/{$device->uuid}/live")->assertForbidden();
    $this->postJson("/api/devices/{$device->uuid}/live/reboot")->assertForbidden();
    $this->postJson("/api/devices/{$device->uuid}/live/alarm")->assertForbidden();
    $this->postJson("/api/devices/{$device->uuid}/live/shutdown")->assertForbidden();
    $this->postJson("/api/devices/{$device->uuid}/live/message", ['sender_name' => 'HQ', 'message_body' => 'hi'])->assertForbidden();
    expect(AuditLog::query()->where('event', 'like', 'device.scalefusion.%')->exists())->toBeFalse();

    // The spec's split: view-only telemetry without the levers.
    $ctx['user']->givePermissionTo(MerchantPermission::DevicesLiveView->value);
    $this->getJson("/api/devices/{$device->uuid}/live")->assertOk();
    $this->postJson("/api/devices/{$device->uuid}/live/reboot")->assertForbidden();
    $this->postJson("/api/devices/{$device->uuid}/live/message", ['sender_name' => 'HQ', 'message_body' => 'hi'])->assertForbidden();

    // Manager holds both seeded keys.
    $mgr = makeMerchantActor(MerchantRole::Manager->value);
    $mine = seedLiveDevice($mgr);
    $this->getJson("/api/devices/{$mine->uuid}/live")->assertOk();
    $this->postJson("/api/devices/{$mine->uuid}/live/reboot")->assertOk();
});

it('relays scalefusion failures and still audits the attempt', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx);

    // Scalefusion's own 404 (unknown kiosk id) is relayed as-is.
    Http::fake(['scalefusion.test/api/v3/devices/7001.json' => Http::response(['message' => 'not found'], 404)]);
    $this->getJson("/api/devices/{$device->uuid}/live")->assertNotFound();

    // An upstream 401 means OUR fleet token is bad, not the caller's
    // session — clamped to 502 so the SPA's global 401 interceptor
    // doesn't force-log the merchant out of the portal. (Second kiosk:
    // Http::fake stubs merge, and the 404 stub above would win for 7001.)
    $second = seedLiveDevice($ctx, null, ['kiosk_id' => '7002']);
    Http::fake(['scalefusion.test/api/v3/devices/7002.json' => Http::response(['message' => 'bad token'], 401)]);
    $this->getJson("/api/devices/{$second->uuid}/live")->assertStatus(502);

    // A 500 on a command relays AND the failed attempt is audited.
    Http::fake(['scalefusion.test/api/v1/devices/7001/reboot.json' => Http::response(['message' => 'boom'], 500)]);
    $this->postJson("/api/devices/{$device->uuid}/live/reboot")
        ->assertStatus(500)
        ->assertJsonPath('ok', false);

    $log = AuditLog::query()->where('event', 'device.scalefusion.reboot')->firstOrFail();
    expect($log->metadata['ok'])->toBeFalse()
        ->and($log->metadata['status'])->toBe(500);
});

it('degrades to 502 with a clean message when no token is configured', function (): void {
    $ctx = makeMerchantActor();
    $device = seedLiveDevice($ctx);

    config(['services.scalefusion.token' => null]);
    Http::fake();

    $this->getJson("/api/devices/{$device->uuid}/live")
        ->assertStatus(502)
        ->assertJsonPath('data.message', 'Scalefusion is not configured.');

    Http::assertNothingSent();
});
