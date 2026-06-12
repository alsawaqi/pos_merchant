<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Staff\CreatePosStaffAction;
use App\Actions\Pos\Staff\ReactivatePosStaffAction;
use App\Actions\Pos\Staff\ResetPosStaffPinAction;
use App\Actions\Pos\Staff\SuspendPosStaffAction;
use App\Actions\Pos\Staff\TerminatePosStaffAction;
use App\Actions\Pos\Staff\UpdatePosStaffAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Staff\CreatePosStaffRequest;
use App\Http\Requests\Pos\Staff\UpdatePosStaffRequest;
use App\Http\Resources\Pos\Staff\PosStaffResource;
use App\Models\PosStaff;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Merchant portal — manage YOUR OWN POS staff (the PIN-
 * authenticated workforce that uses the Android device).
 *
 *   GET    /api/pos-staff                       → list (active + suspended)
 *   POST   /api/pos-staff                       → hire (returns one-shot PIN)
 *   PATCH  /api/pos-staff/{posStaff}            → update fields
 *   POST   /api/pos-staff/{posStaff}/suspend    → suspend
 *   POST   /api/pos-staff/{posStaff}/reactivate → reactivate
 *   POST   /api/pos-staff/{posStaff}/terminate  → end employment (soft delete)
 *   POST   /api/pos-staff/{posStaff}/reset-pin  → mint new PIN (one-shot)
 *
 * Auto-scoped to the actor's company via MerchantTenantContext —
 * no company uuid in the URL because merchants only manage their
 * own roster.
 *
 * Permission gating uses MerchantPermission directly (same
 * pattern as PortalUsersController — no Policy class, because
 * the spatie team_id has already been pinned by the SetMerchantTenantContext
 * middleware so $user->can() reads the right team).
 */
class PosStaffController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreatePosStaffAction $create,
        private readonly UpdatePosStaffAction $update,
        private readonly SuspendPosStaffAction $suspend,
        private readonly ReactivatePosStaffAction $reactivate,
        private readonly TerminatePosStaffAction $terminate,
        private readonly ResetPosStaffPinAction $resetPin,
    ) {}

    /**
     * GET /api/pos-staff
     *
     * Lists active + suspended staff at the actor's company,
     * newest first. Terminated rows are excluded by the
     * SoftDeletes scope. Eager-loads branch + creator so the
     * resource doesn't N+1 on the table render.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::PosStaffView);

        // P-G5 — staff are branch-assigned; a scoped user sees only
        // the roster of their branches.
        $allowed = $request->user()?->allowedBranchIds();

        $staff = PosStaff::query()
            ->where('company_id', $this->tenant->requiredId())
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed))
            ->with(['branch', 'creator'])
            ->orderByDesc('created_at')
            ->get();

        return PosStaffResource::collection($staff);
    }

    /**
     * POST /api/pos-staff
     */
    public function store(CreatePosStaffRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::PosStaffCreate);

        // P-G5 — hire only into branches within the user's scope.
        \App\Support\BranchScope::ensureBranch($request->user(), (int) $request->validated()['branch_id']);

        try {
            $result = $this->create->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result['staff']->load(['branch', 'creator']);

        return response()->json([
            'data' => (new PosStaffResource($result['staff']))->resolve($request),
            'plaintext_pin' => $result['plaintext_pin'],
        ], 201);
    }

    /**
     * PATCH /api/pos-staff/{posStaff}
     */
    public function update(UpdatePosStaffRequest $request, PosStaff $posStaff): PosStaffResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::PosStaffUpdate);
        $this->refuseIfNotInTenant($posStaff);

        // P-G5 — the row's CURRENT branch is covered by the route
        // middleware; a re-assignment target must be in scope too.
        $newBranchId = $request->validated()['branch_id'] ?? null;
        if ($newBranchId !== null) {
            \App\Support\BranchScope::ensureBranch($request->user(), (int) $newBranchId);
        }

        try {
            $updated = $this->update->handle($posStaff, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load(['branch', 'creator']);

        return PosStaffResource::make($updated);
    }

    /**
     * POST /api/pos-staff/{posStaff}/suspend
     */
    public function suspend(Request $request, PosStaff $posStaff): PosStaffResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::PosStaffRevoke);
        $this->refuseIfNotInTenant($posStaff);

        try {
            $updated = $this->suspend->handle($posStaff, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load(['branch', 'creator']);

        return PosStaffResource::make($updated);
    }

    /**
     * POST /api/pos-staff/{posStaff}/reactivate
     */
    public function reactivate(Request $request, PosStaff $posStaff): PosStaffResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::PosStaffRevoke);
        $this->refuseIfNotInTenant($posStaff);

        try {
            $updated = $this->reactivate->handle($posStaff, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load(['branch', 'creator']);

        return PosStaffResource::make($updated);
    }

    /**
     * POST /api/pos-staff/{posStaff}/terminate
     */
    public function terminate(Request $request, PosStaff $posStaff): PosStaffResource
    {
        $this->ensure($request, MerchantPermission::PosStaffRevoke);
        $this->refuseIfNotInTenant($posStaff);

        $updated = $this->terminate->handle($posStaff, $request->user());
        $updated->load(['branch', 'creator']);

        return PosStaffResource::make($updated);
    }

    /**
     * POST /api/pos-staff/{posStaff}/reset-pin
     */
    public function resetPin(Request $request, PosStaff $posStaff): JsonResponse
    {
        $this->ensure($request, MerchantPermission::PosStaffUpdate);
        $this->refuseIfNotInTenant($posStaff);

        try {
            $result = $this->resetPin->handle($posStaff, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result['staff']->load(['branch', 'creator']);

        return response()->json([
            'data' => (new PosStaffResource($result['staff']))->resolve($request),
            'plaintext_pin' => $result['plaintext_pin'],
        ]);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /**
     * The router binds {posStaff} by uuid (PosStaff::getRouteKeyName),
     * but that lookup doesn't validate company ownership. Without
     * this guard, any merchant with the right permission could pass
     * another merchant's uuid in the URL.
     */
    private function refuseIfNotInTenant(PosStaff $posStaff): void
    {
        if ((int) $posStaff->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
