<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\TransferStockAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\CreateBranchTransferRequest;
use App\Http\Resources\Pos\Inventory\BranchTransferResource;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Branch stock transfers (§5.6).
 *
 *   GET  /api/branch-transfers                          → list (all branches)
 *   GET  /api/branch-transfers/{transfer:uuid}          → show one
 *   POST /api/branches/{branch:uuid}/transfers          → transfer FROM this
 *                                                          branch (immediate)
 *
 * Read gated on InventoryView; the transfer mutation on InventoryManage.
 * Tenant-scoped via MerchantTenantContext; branch + transfer ownership
 * re-checked on every endpoint.
 */
class BranchTransfersController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly TransferStockAction $transfer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);

        $companyId = $this->tenant->requiredId();
        $query = BranchTransfer::query()
            ->where('company_id', $companyId)
            ->with(['fromBranch', 'toBranch', 'lines.ingredient']);

        if ($request->filled('branch')) {
            $branchId = Branch::query()
                ->where('uuid', $request->query('branch'))
                ->where('company_id', $companyId)
                ->value('id');
            // Either side of the move matches the filter branch.
            $query->where(function ($q) use ($branchId): void {
                $q->where('from_branch_id', $branchId ?? -1)
                    ->orWhere('to_branch_id', $branchId ?? -1);
            });
        }

        $transfers = $query
            ->orderByDesc('transferred_at')
            ->orderByDesc('id')
            ->get();

        return BranchTransferResource::collection($transfers);
    }

    public function show(Request $request, BranchTransfer $transfer): BranchTransferResource
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($transfer);

        $transfer->load(['fromBranch', 'toBranch', 'lines.ingredient']);

        return BranchTransferResource::make($transfer);
    }

    public function store(CreateBranchTransferRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfBranchNotInTenant($branch);

        $to = Branch::query()
            ->where('uuid', $request->validated()['to_branch_uuid'])
            ->where('company_id', $this->tenant->requiredId())
            ->first();
        if ($to === null) {
            return response()->json(['message' => 'The destination branch does not belong to your company.'], 422);
        }

        try {
            $transfer = $this->transfer->handle(
                from: $branch,
                to: $to,
                lines: $request->validated()['lines'],
                actor: $request->user(),
                note: $request->validated()['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new BranchTransferResource($transfer))->resolve($request),
        ], 201);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(BranchTransfer $transfer): void
    {
        if ((int) $transfer->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }

    private function refuseIfBranchNotInTenant(Branch $branch): void
    {
        if ((int) $branch->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
