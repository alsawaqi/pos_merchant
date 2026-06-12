<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\CreatePortalUserAction;
use App\Actions\Portal\ReactivatePortalUserAction;
use App\Actions\Portal\ResetPortalUserPasswordAction;
use App\Actions\Portal\SuspendPortalUserAction;
use App\Actions\Portal\UpdatePortalUserAction;
use App\Actions\Pos\Role\AssignRolesToUserAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\CreatePortalUserRequest;
use App\Http\Requests\Portal\UpdatePortalUserRequest;
use App\Http\Resources\Portal\PortalUserResource;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Merchant portal — manage YOUR OWN team's portal users.
 *
 *   GET    /api/portal-users                    → list
 *   POST   /api/portal-users                    → create teammate
 *   PATCH  /api/portal-users/{user}             → update (name/phone/role/scope)
 *   POST   /api/portal-users/{user}/suspend     → suspend
 *   POST   /api/portal-users/{user}/reactivate  → reactivate
 *   POST   /api/portal-users/{user}/reset-password → mint a new pw
 *
 * All endpoints are auto-scoped to the signed-in user's
 * company_id via MerchantTenantContext — there is no company
 * uuid in the URL because the merchant only ever manages their
 * own team.
 *
 * Permission gating uses MerchantPermission directly (no Policy
 * class because the User model is shared with pos_admin's
 * PortalUserPolicy and a second policy mapping would collide).
 * The middleware has already pinned spatie's team_id to the
 * actor's company_id, so $user->can(...) reads the right team.
 *
 * Cross-tenant safety: each mutation re-checks user->company_id
 * matches the actor's tenant — defence in depth on top of the
 * indexed WHERE clause that scopes the list.
 */
class PortalUsersController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreatePortalUserAction $create,
        private readonly UpdatePortalUserAction $update,
        private readonly SuspendPortalUserAction $suspend,
        private readonly ReactivatePortalUserAction $reactivate,
        private readonly ResetPortalUserPasswordAction $resetPassword,
        private readonly AssignRolesToUserAction $assignRoles,
    ) {}

    /**
     * GET /api/portal-users
     *
     * Lists every teammate in the actor's company, newest first.
     * No pagination yet — most merchants will have < 20 portal
     * users; pagination lands when any pilot tenant exceeds that.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::PortalUsersView);

        $users = User::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('user_type', 'merchant')
            ->orderByDesc('created_at')
            ->get();

        return PortalUserResource::collection($users);
    }

    /**
     * POST /api/portal-users
     *
     * Returns the new user + a one-shot plaintext password the
     * SPA surfaces in a copy-once modal then forgets.
     */
    public function store(CreatePortalUserRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::PortalUsersInvite);
        // On CREATE an omitted branch_scope defaults to NULL (all
        // branches), so a restricted actor is ALWAYS checked.
        $this->ensureGrantWithinScope($request, true);

        try {
            $result = $this->create->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new PortalUserResource($result['user']))->resolve($request),
            'plaintext_password' => $result['plaintext_password'],
        ], 201);
    }

    /**
     * PATCH /api/portal-users/{user}
     */
    public function update(UpdatePortalUserRequest $request, User $portalUser): PortalUserResource
    {
        $this->ensure($request, MerchantPermission::PortalUsersUpdate);
        $this->refuseIfNotInTenant($portalUser);
        $this->ensureGrantWithinScope($request, array_key_exists('branch_scope', $request->validated()));

        return PortalUserResource::make(
            $this->update->handle($portalUser, $request->validated(), $request->user()),
        );
    }

    /**
     * P-G5 meta-rule — a branch-restricted admin may only grant a
     * teammate branch_scope that is a NON-NULL SUBSET of their own
     * scope: they can neither hand out "all branches" (null) nor any
     * branch they themselves cannot see.
     *
     * $scopeInPayload guards the UPDATE case (a 'sometimes' field — an
     * absent key means "leave the teammate's scope unchanged", which is
     * legitimately a no-op). On CREATE pass true: an absent key defaults
     * the new user to NULL (all branches), so a restricted actor must
     * always be checked — guarding on key-presence there was the hole.
     */
    private function ensureGrantWithinScope(Request $request, bool $scopeInPayload): void
    {
        if (! $scopeInPayload) {
            return;
        }
        $actorScope = $request->user()?->allowedBranchIds();
        if ($actorScope === null) {
            return;
        }

        // Absent on CREATE = NULL = all branches → refused below.
        $granted = $request->validated()['branch_scope'] ?? null;
        if ($granted === null) {
            abort(403, 'Your account is restricted to specific branches — you cannot grant access to all branches.');
        }
        foreach ((array) $granted as $id) {
            if (! in_array((int) $id, $actorScope, true)) {
                abort(403, 'You can only grant access to branches within your own scope.');
            }
        }
    }

    /**
     * POST /api/portal-users/{user}/suspend
     *
     * Action refuses self-suspension with a RuntimeException; we
     * surface it as 422 with the inline message.
     */
    public function suspend(Request $request, User $portalUser): PortalUserResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::PortalUsersRevoke);
        $this->refuseIfNotInTenant($portalUser);

        try {
            $updated = $this->suspend->handle($portalUser, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return PortalUserResource::make($updated);
    }

    /**
     * POST /api/portal-users/{user}/reactivate
     */
    public function reactivate(Request $request, User $portalUser): PortalUserResource
    {
        $this->ensure($request, MerchantPermission::PortalUsersRevoke);
        $this->refuseIfNotInTenant($portalUser);

        return PortalUserResource::make(
            $this->reactivate->handle($portalUser, $request->user()),
        );
    }

    /**
     * POST /api/portal-users/{user}/reset-password
     *
     * Returns the new plaintext password ONCE — same envelope as
     * the create response.
     */
    public function resetPassword(Request $request, User $portalUser): JsonResponse
    {
        $this->ensure($request, MerchantPermission::PortalUsersInvite);
        $this->refuseIfNotInTenant($portalUser);

        $result = $this->resetPassword->handle($portalUser, $request->user());

        return response()->json([
            'data' => (new PortalUserResource($result['user']))->resolve($request),
            'plaintext_password' => $result['plaintext_password'],
        ]);
    }

    /**
     * PATCH /api/portal-users/{user}/roles
     *
     * Phase 4.8 — replace the user's role list with the
     * requested set. The Action layer enforces the
     * self-rescue invariant (actor can't remove their own
     * SuperAdmin role).
     *
     * Gated by RolesManage rather than PortalUsersUpdate
     * because role assignment is a meta-control that bypasses
     * the normal "what can this user do" boundary —
     * a Manager who has PortalUsersUpdate can rename a
     * teammate, but should NOT be able to promote them to
     * SuperAdmin.
     */
    public function assignRoles(Request $request, User $portalUser): PortalUserResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::RolesManage);
        $this->refuseIfNotInTenant($portalUser);

        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'max:64'],
        ]);

        try {
            $updated = $this->assignRoles->handle(
                $portalUser,
                $validated['roles'],
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return PortalUserResource::make($updated);
    }

    /**
     * Direct permission check — no Policy involved (User model is
     * shared with pos_admin which owns the Policy mapping).
     */
    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /**
     * Guard against operating on a teammate from a different
     * merchant. The route binding resolves by `pos_users.id` (no
     * uuid yet on this table); without this check an admin with
     * the right permission could touch any user id.
     */
    private function refuseIfNotInTenant(User $portalUser): void
    {
        if ((int) $portalUser->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
        if ($portalUser->user_type !== 'merchant') {
            abort(404);
        }
    }
}
