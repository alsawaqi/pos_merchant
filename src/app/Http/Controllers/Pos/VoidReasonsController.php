<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\OrderReasons\EnsureDefaultOrderReasonsAction;
use App\Actions\Pos\OrderReasons\SaveVoidReasonAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\OrderReasons\SaveVoidReasonRequest;
use App\Http\Resources\Pos\OrderReasons\VoidReasonResource;
use App\Models\VoidReason;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase B — company void reason codes CRUD (Additions §1.2).
 *
 *   GET    /api/void-reasons
 *   POST   /api/void-reasons
 *   PATCH  /api/void-reasons/{voidReason:uuid}
 *   DELETE /api/void-reasons/{voidReason:uuid}
 *
 * Gated by OrdersCancel — the same money-adjacent lever as the order
 * cancellation policy these reasons feed (both ship to the device in
 * /device/config). Index seeds the doc defaults on first open.
 */
class VoidReasonsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SaveVoidReasonAction $save,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request);

        app(EnsureDefaultOrderReasonsAction::class)->handle($this->tenant->requiredId());

        return VoidReasonResource::collection(
            VoidReason::query()
                ->where('company_id', $this->tenant->requiredId())
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(SaveVoidReasonRequest $request): JsonResponse
    {
        $this->ensure($request);

        try {
            $reason = $this->save->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new VoidReasonResource($reason))->resolve($request),
        ], 201);
    }

    public function update(SaveVoidReasonRequest $request, VoidReason $voidReason): VoidReasonResource|JsonResponse
    {
        $this->ensure($request);

        try {
            $updated = $this->save->handle($request->validated(), $request->user(), $voidReason);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return VoidReasonResource::make($updated);
    }

    public function destroy(Request $request, VoidReason $voidReason): JsonResponse
    {
        $this->ensure($request);
        if ((int) $voidReason->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }

        $voidReason->delete();
        $this->writeAuditLog->handle(new AuditLogData(
            event: 'settings.void_reason.deleted',
            actorUserId: $request->user()->getKey(),
            companyId: $this->tenant->requiredId(),
            auditableType: VoidReason::class,
            auditableId: $voidReason->id,
            oldValues: ['code' => $voidReason->code, 'name' => $voidReason->name],
        ));

        return response()->json(['data' => null], 204);
    }

    private function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::OrdersCancel->value)) {
            abort(403);
        }
    }
}
