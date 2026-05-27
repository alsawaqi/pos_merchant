<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\FloorPlan\CreateFloorAction;
use App\Actions\Pos\FloorPlan\DeleteFloorAction;
use App\Actions\Pos\FloorPlan\UpdateFloorAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\FloorPlan\CreateFloorRequest;
use App\Http\Requests\Pos\FloorPlan\UpdateFloorRequest;
use App\Http\Resources\Pos\FloorPlan\FloorResource;
use App\Models\Branch;
use App\Models\Floor;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Manage floors under a branch. Routes are branch-nested
 * because a floor is meaningless without the branch context:
 *
 *   GET    /api/branches/{branch:uuid}/floors            → list (with tables eager-loaded)
 *   POST   /api/branches/{branch:uuid}/floors            → create
 *   PATCH  /api/floors/{floor:uuid}                      → update
 *   DELETE /api/floors/{floor:uuid}                      → delete (refuses if has tables)
 *
 * The update + delete routes are flat (not branch-nested)
 * because the floor UUID already identifies the tenant
 * scope and nested URLs would be redundant.
 *
 * Permission gates:
 *   - index  → FloorPlanView
 *   - mutate → FloorPlanManage
 */
class FloorsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateFloorAction $create,
        private readonly UpdateFloorAction $update,
        private readonly DeleteFloorAction $delete,
    ) {}

    /**
     * GET /api/branches/{branch:uuid}/floors
     *
     * Returns every floor of the branch, ordered by
     * display_order then name, with tables eager-loaded.
     */
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::FloorPlanView);
        $this->refuseIfBranchNotInTenant($branch);

        $floors = Floor::query()
            ->where('branch_id', $branch->id)
            ->with(['tables' => function ($q): void {
                $q->orderBy('display_order')->orderBy('label');
            }])
            ->withCount('tables')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return FloorResource::collection($floors);
    }

    /**
     * POST /api/branches/{branch:uuid}/floors
     */
    public function store(CreateFloorRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::FloorPlanManage);
        $this->refuseIfBranchNotInTenant($branch);

        $floor = $this->create->handle($branch, $request->validated(), $request->user());

        return response()->json([
            'data' => (new FloorResource($floor))->resolve($request),
        ], 201);
    }

    /**
     * PATCH /api/floors/{floor:uuid}
     */
    public function update(UpdateFloorRequest $request, Floor $floor): FloorResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::FloorPlanManage);
        $this->refuseIfNotInTenant($floor);

        $updated = $this->update->handle($floor, $request->validated(), $request->user());

        return FloorResource::make($updated);
    }

    /**
     * DELETE /api/floors/{floor:uuid}
     */
    public function destroy(Request $request, Floor $floor): JsonResponse
    {
        $this->ensure($request, MerchantPermission::FloorPlanManage);
        $this->refuseIfNotInTenant($floor);

        try {
            $this->delete->handle($floor, $request->user());
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

    private function refuseIfBranchNotInTenant(Branch $branch): void
    {
        if ((int) $branch->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    private function refuseIfNotInTenant(Floor $floor): void
    {
        if ((int) $floor->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
