<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\OrderReasons\EnsureDefaultOrderReasonsAction;
use App\Actions\Pos\OrderReasons\SaveCompReasonAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\OrderReasons\SaveCompReasonRequest;
use App\Http\Resources\Pos\OrderReasons\CompReasonResource;
use App\Models\CompReason;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase B — company comp reasons CRUD (Additions §1.2). Same gating
 * + lifecycle as {@see VoidReasonsController}.
 *
 *   GET    /api/comp-reasons
 *   POST   /api/comp-reasons
 *   PATCH  /api/comp-reasons/{compReason:uuid}
 *   DELETE /api/comp-reasons/{compReason:uuid}
 */
class CompReasonsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SaveCompReasonAction $save,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request);

        app(EnsureDefaultOrderReasonsAction::class)->handle($this->tenant->requiredId());

        return CompReasonResource::collection(
            CompReason::query()
                ->where('company_id', $this->tenant->requiredId())
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(SaveCompReasonRequest $request): JsonResponse
    {
        $this->ensure($request);

        try {
            $reason = $this->save->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new CompReasonResource($reason))->resolve($request),
        ], 201);
    }

    public function update(SaveCompReasonRequest $request, CompReason $compReason): CompReasonResource|JsonResponse
    {
        $this->ensure($request);

        try {
            $updated = $this->save->handle($request->validated(), $request->user(), $compReason);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return CompReasonResource::make($updated);
    }

    public function destroy(Request $request, CompReason $compReason): JsonResponse
    {
        $this->ensure($request);
        if ((int) $compReason->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }

        $compReason->delete();
        $this->writeAuditLog->handle(new AuditLogData(
            event: 'settings.comp_reason.deleted',
            actorUserId: $request->user()->getKey(),
            companyId: $this->tenant->requiredId(),
            auditableType: CompReason::class,
            auditableId: $compReason->id,
            oldValues: ['code' => $compReason->code, 'name' => $compReason->name],
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
