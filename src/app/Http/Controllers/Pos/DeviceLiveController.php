<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use App\Support\ScalefusionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P-G9 — the merchant's RESTRICTED Live (scalefusion MDM) surface for
 * its own devices: full telemetry view + exactly four safe actions
 * (restart, shutdown, on-screen message, beep). The sharp verbs —
 * lock / unlock / mark-lost / wipe / factory reset / delete — stay
 * admin-only: no route here reaches them, and the underlying
 * {@see ScalefusionClient} doesn't implement them either, so the
 * whitelist holds even if a route were misdeclared.
 *
 * Telemetry reads are gated by devices.live.view, the commands by
 * devices.live.control (split per the spec so view-only telemetry can
 * be handed out without the levers). Every endpoint runs the tenant
 * 404 BEFORE the F5 scope 403 (a foreign uuid never reveals its
 * existence), and every command — success or
 * failure — lands in the shared audit log under
 * device.scalefusion.<action>, the same envelope pos_admin writes,
 * so the platform's Audit Log viewer shows merchant-issued MDM
 * commands alongside admin-issued ones.
 *
 * Branch scope: a device belongs to a branch; a branch-restricted
 * user only reaches devices on their branches. The rare unassigned
 * device (branch_id NULL) is company-central — HQ-only, per the F5
 * convention for NULL-branch records.
 */
class DeviceLiveController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly ScalefusionClient $scalefusion,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * GET /api/devices/{device:uuid}/live
     *
     * Full live device detail (RAM, storage, CPU/thermals, battery,
     * network, management, location) from scalefusion's v3 API,
     * relayed raw for the Live dialog to render.
     */
    public function show(Request $request, Device $device): JsonResponse
    {
        $this->gate($request, $device, MerchantPermission::DevicesLiveView);

        $kioskId = $this->requireKioskId($device);
        $result = $this->scalefusion->getDevice($kioskId);

        return response()->json(
            ['data' => $result['data']],
            $result['ok'] ? 200 : $this->failureStatus($result),
        );
    }

    /** POST /api/devices/{device:uuid}/live/reboot */
    public function reboot(Request $request, Device $device): JsonResponse
    {
        return $this->control($request, $device, 'reboot', fn (string $id) => $this->scalefusion->reboot($id));
    }

    /** POST /api/devices/{device:uuid}/live/shutdown */
    public function shutdown(Request $request, Device $device): JsonResponse
    {
        return $this->control($request, $device, 'action:shutdown', fn (string $id) => $this->scalefusion->shutdown($id));
    }

    /** POST /api/devices/{device:uuid}/live/alarm — the beep/buzz. */
    public function alarm(Request $request, Device $device): JsonResponse
    {
        return $this->control($request, $device, 'alarm', fn (string $id) => $this->scalefusion->sendAlarm($id));
    }

    /**
     * POST /api/devices/{device:uuid}/live/message
     *
     * On-screen MDM broadcast (renders at the OS level via the MDM
     * agent — distinct from P-G6 in-app messaging).
     */
    public function message(Request $request, Device $device): JsonResponse
    {
        // Gate BEFORE validation: a 422 for an invalid body would otherwise
        // fire ahead of the tenant 404 and confirm a foreign uuid exists.
        $this->gate($request, $device, MerchantPermission::DevicesLiveControl);

        $validated = $request->validate([
            'sender_name' => ['required', 'string', 'max:100'],
            'message_body' => ['required', 'string', 'max:1000'],
            'keep_ringing' => ['nullable', 'boolean'],
            'show_as_dialog' => ['nullable', 'boolean'],
        ]);

        return $this->control(
            $request,
            $device,
            'broadcast_message',
            fn (string $id) => $this->scalefusion->broadcastMessage(
                $id,
                $validated['sender_name'],
                $validated['message_body'],
                $request->boolean('keep_ringing', true),
                $request->boolean('show_as_dialog', true),
            ),
        );
    }

    /**
     * Shared command pipeline: gate, tenant 404, F5 scope 403, kiosk
     * id 422, run the scalefusion call, audit (success AND failure),
     * relay the outcome.
     *
     * @param  callable(string): array{ok: bool, status: int, data: mixed}  $run
     */
    private function control(Request $request, Device $device, string $action, callable $run): JsonResponse
    {
        $this->gate($request, $device, MerchantPermission::DevicesLiveControl);

        $kioskId = $this->requireKioskId($device);
        $result = $run($kioskId);

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'device.scalefusion.'.$action,
            actorUserId: $request->user()?->id,
            companyId: $device->company_id,
            branchId: $device->branch_id,
            auditableType: Device::class,
            auditableId: $device->id,
            metadata: [
                'kiosk_id' => $kioskId,
                'ok' => $result['ok'],
                'status' => $result['status'],
                'origin' => 'merchant_portal',
            ],
        ));

        return response()->json(
            [
                'ok' => $result['ok'],
                'data' => $result['data'],
            ],
            $result['ok'] ? 200 : $this->failureStatus($result),
        );
    }

    /**
     * The full guard stack, in the fixed order every endpoint must use:
     * permission 403 → tenant 404 → F5 scope 403. Runs before ANYTHING
     * else (incl. body validation) so a foreign uuid never produces a
     * response that differs from a nonexistent one for the same caller.
     */
    private function gate(Request $request, Device $device, MerchantPermission $permission): void
    {
        $this->ensure($request, $permission);
        $this->refuseIfNotInTenant($device);
        $this->ensureScope($request, $device);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /** Tenant check first — 404, never 403, for a foreign device. */
    private function refuseIfNotInTenant(Device $device): void
    {
        if ((int) $device->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    /**
     * F5 — a branch-restricted user only reaches devices on their own
     * branches; an unassigned (NULL-branch) device is HQ-only.
     */
    private function ensureScope(Request $request, Device $device): void
    {
        if ($device->branch_id === null) {
            BranchScope::ensureUnrestricted($request->user());

            return;
        }

        BranchScope::ensureBranch($request->user(), (int) $device->branch_id);
    }

    /**
     * A device must carry a kiosk_id to be addressable in scalefusion.
     */
    private function requireKioskId(Device $device): string
    {
        $kioskId = trim((string) $device->kiosk_id);

        abort_if($kioskId === '', 422, 'This device has no scalefusion kiosk id, so it cannot be reached.');

        return $kioskId;
    }

    /**
     * Relay scalefusion's own 4xx/5xx (e.g. 404 unknown device); fall
     * back to 502 for transport-level failures (status 0). Upstream
     * auth statuses are clamped to 502: a 401 here means OUR fleet
     * token is bad, not the caller's session — and a relayed 401 would
     * trip the SPA's global auth interceptor and force-log the
     * merchant out of the portal.
     *
     * @param  array{ok: bool, status: int, data: mixed}  $result
     */
    private function failureStatus(array $result): int
    {
        if (in_array($result['status'], [401, 403], true)) {
            return 502;
        }

        return $result['status'] >= 400 ? $result['status'] : 502;
    }
}
