<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Read-only listing of the actor's company's branches. Powers
 * the branch-scope multi-select on the Portal Users create modal
 * (which branches the teammate can access) + the future Branches
 * read-only view (Phase 4.6).
 *
 * No permission gate beyond "must be authenticated" — every
 * merchant user needs the branch list to render UI. The actual
 * data scope is enforced by the tenant context filter, not a
 * permission check.
 */
class BranchesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
    ) {}

    public function index(): JsonResponse
    {
        $branches = Branch::query()
            ->where('company_id', $this->tenant->requiredId())
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'name_ar', 'code', 'status']);

        return response()->json([
            'data' => $branches->map(static fn (Branch $b): array => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'name' => $b->name,
                'name_ar' => $b->name_ar,
                'code' => $b->code,
                'status' => $b->status,
            ])->all(),
        ]);
    }
}
