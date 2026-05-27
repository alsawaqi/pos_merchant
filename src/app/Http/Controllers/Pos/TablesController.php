<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\FloorPlan\CreateTableAction;
use App\Actions\Pos\FloorPlan\DeleteTableAction;
use App\Actions\Pos\FloorPlan\RegenerateTableQrAction;
use App\Actions\Pos\FloorPlan\UpdateTableAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\FloorPlan\CreateTableRequest;
use App\Http\Requests\Pos\FloorPlan\UpdateTableRequest;
use App\Http\Resources\Pos\FloorPlan\TableResource;
use App\Models\Floor;
use App\Models\Table;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   POST   /api/floors/{floor:uuid}/tables           → create
 *   PATCH  /api/tables/{table:uuid}                  → update
 *   DELETE /api/tables/{table:uuid}                  → soft delete
 *   POST   /api/tables/{table:uuid}/regenerate-qr    → roll qr_token
 *
 * No standalone index — tables are always returned as
 * children of a floor (FloorResource eager-loads them).
 *
 * Permission gate: FloorPlanManage for every mutation.
 * Reading is implicit in FloorPlanView (covered by the
 * floor index endpoint).
 */
class TablesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateTableAction $create,
        private readonly UpdateTableAction $update,
        private readonly DeleteTableAction $delete,
        private readonly RegenerateTableQrAction $regenerateQr,
    ) {}

    /**
     * POST /api/floors/{floor:uuid}/tables
     */
    public function store(CreateTableRequest $request, Floor $floor): JsonResponse
    {
        $this->ensure($request, MerchantPermission::FloorPlanManage);
        $this->refuseIfFloorNotInTenant($floor);

        $table = $this->create->handle($floor, $request->validated(), $request->user());

        return response()->json([
            'data' => (new TableResource($table))->resolve($request),
        ], 201);
    }

    /**
     * PATCH /api/tables/{table:uuid}
     */
    public function update(UpdateTableRequest $request, Table $table): TableResource
    {
        $this->ensure($request, MerchantPermission::FloorPlanManage);
        $this->refuseIfNotInTenant($table);

        $updated = $this->update->handle($table, $request->validated(), $request->user());

        return TableResource::make($updated);
    }

    /**
     * DELETE /api/tables/{table:uuid}
     */
    public function destroy(Request $request, Table $table): JsonResponse
    {
        $this->ensure($request, MerchantPermission::FloorPlanManage);
        $this->refuseIfNotInTenant($table);

        $this->delete->handle($table, $request->user());

        return response()->json(['data' => null], 204);
    }

    /**
     * POST /api/tables/{table:uuid}/regenerate-qr
     *
     * Returns the table with its new qr_token in the
     * envelope so the SPA can offer "copy / print new card"
     * UX immediately. The old token is invalidated atomically.
     */
    public function regenerateQr(Request $request, Table $table): JsonResponse
    {
        $this->ensure($request, MerchantPermission::FloorPlanManage);
        $this->refuseIfNotInTenant($table);

        $result = $this->regenerateQr->handle($table, $request->user());

        return response()->json([
            'data' => (new TableResource($result['table']))->resolve($request),
            'qr_token' => $result['qr_token'],
        ]);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfFloorNotInTenant(Floor $floor): void
    {
        if ((int) $floor->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    private function refuseIfNotInTenant(Table $table): void
    {
        if ((int) $table->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
