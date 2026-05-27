<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Role\CreateRoleAction;
use App\Actions\Pos\Role\DeleteRoleAction;
use App\Actions\Pos\Role\UpdateRoleAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Role\CreateRoleRequest;
use App\Http\Requests\Pos\Role\UpdateRoleRequest;
use App\Http\Resources\Pos\Role\RoleResource;
use App\Support\MerchantTenantContext;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Merchant portal — manage YOUR OWN company's roles.
 *
 *   GET    /api/roles              → list roles (system + custom)
 *   GET    /api/roles/catalog      → permission catalog (grouped)
 *   POST   /api/roles              → create custom role
 *   PATCH  /api/roles/{role}       → edit (system roles partial)
 *   DELETE /api/roles/{role}       → delete (custom + unused only)
 *
 * Auto-scoped to the actor's company via MerchantTenantContext.
 * Roles bound by integer id (spatie's default route key) — the
 * names are not stable URLs because a custom role can be
 * renamed.
 *
 * Permission gates:
 *   - index / catalog: RolesView (every authed merchant role
 *     gets this by default — they need to know what roles
 *     exist to ask for one)
 *   - store / update / destroy: RolesManage (SuperAdmin only
 *     by default)
 */
class RolesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateRoleAction $create,
        private readonly UpdateRoleAction $update,
        private readonly DeleteRoleAction $delete,
    ) {}

    /**
     * GET /api/roles
     *
     * Returns every role under the actor's company team scope,
     * with assigned permissions + user counts eager-loaded so
     * the table renders without N+1.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::RolesView);

        $companyId = $this->tenant->requiredId();
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($companyId);

        $roles = Role::query()
            ->where('team_id', $companyId)
            ->where('guard_name', 'web')
            ->with('permissions')
            ->withCount('users')
            ->orderByDesc('is_system')   // system roles first
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    /**
     * GET /api/roles/catalog
     *
     * The full permission catalog used by the role-editor's
     * grouped checkbox grid. Returned regardless of which roles
     * the actor's company has assigned anywhere — the catalog
     * is enum-derived, not data-derived.
     */
    public function catalog(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::RolesView);

        return response()->json([
            'data' => PermissionCatalog::merchant(),
        ]);
    }

    /**
     * POST /api/roles
     */
    public function store(CreateRoleRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::RolesManage);

        $role = $this->create->handle($request->validated(), $request->user());
        $role->load('permissions');

        return response()->json([
            'data' => (new RoleResource($role))->resolve($request),
        ], 201);
    }

    /**
     * PATCH /api/roles/{role}
     */
    public function update(UpdateRoleRequest $request, Role $role): RoleResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::RolesManage);
        $this->refuseIfNotInTenant($role);

        try {
            $updated = $this->update->handle($role, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load('permissions');

        return RoleResource::make($updated);
    }

    /**
     * DELETE /api/roles/{role}
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->ensure($request, MerchantPermission::RolesManage);
        $this->refuseIfNotInTenant($role);

        try {
            $this->delete->handle($role, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => null], 204);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    /**
     * Roles are team-scoped — the spatie default route binding
     * resolves by id, which doesn't enforce team_id. Add the
     * tenancy check here so a Manager-tier user can't probe a
     * role id from another company.
     */
    private function refuseIfNotInTenant(Role $role): void
    {
        if ((int) $role->team_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
