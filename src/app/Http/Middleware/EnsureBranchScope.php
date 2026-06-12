<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Expense;
use App\Models\Floor;
use App\Models\PosStaff;
use App\Models\RestockRequest;
use App\Models\Shift;
use App\Models\Table;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P-G5 — route-level branch-scope enforcement, one place for every
 * branch-carrying bound model. Runs after SubstituteBindings (appended
 * last to the web group), so route parameters are already models.
 *
 * For a branch-restricted user (allowedBranchIds() !== null) any bound
 * model whose branch lies outside the scope aborts 403. Param → branch
 * derivations:
 *
 *   branch          → itself
 *   floor           → floor.branch_id
 *   table           → table.floor.branch_id
 *   shift           → shift.branch_id
 *   expense         → expense.branch_id (NULL = company-wide → HQ only)
 *   posStaff        → pos_staff.branch_id
 *   transfer        → EITHER side in scope is enough to SEE a
 *                     BranchTransfer (it names two branches; writes go
 *                     through branch-bound routes + body checks)
 *   restockRequest  → restock_request.branch_id
 *
 * CROSS-TENANT ORDERING: a model owned by ANOTHER company is left for
 * the controller's tenant check to 404 — scope must never turn a
 * should-be-404 into a 403 that reveals the uuid exists.
 *
 * Everything the URL does NOT carry (query filters, body branch uuids,
 * implicit all-branch lists, reports) is enforced at its own choke
 * point: ReportFilter, the list controllers, and the body resolvers.
 */
class EnsureBranchScope
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->allowedBranchIds() !== null) {
            $this->enforce($request, $user);
        }

        return $next($request);
    }

    private function enforce(Request $request, User $user): void
    {
        $route = $request->route();
        if ($route === null) {
            return;
        }
        $tenantId = $this->tenant->id();

        $branch = $route->parameter('branch');
        if ($branch instanceof Branch && $this->inTenant($branch->company_id, $tenantId)) {
            $this->ensureAllowed($user, (int) $branch->id);
        }

        $floor = $route->parameter('floor');
        if ($floor instanceof Floor && $this->inTenant($floor->company_id, $tenantId)) {
            $this->ensureAllowed($user, (int) $floor->branch_id);
        }

        $table = $route->parameter('table');
        if ($table instanceof Table && $this->inTenant($table->company_id, $tenantId)) {
            $this->ensureAllowed($user, $table->floor !== null ? (int) $table->floor->branch_id : null);
        }

        $shift = $route->parameter('shift');
        if ($shift instanceof Shift && $this->inTenant($shift->company_id, $tenantId)) {
            $this->ensureAllowed($user, (int) $shift->branch_id);
        }

        $expense = $route->parameter('expense');
        if ($expense instanceof Expense && $this->inTenant($expense->company_id, $tenantId)) {
            // NULL branch = a company-wide (HQ) expense.
            $this->ensureAllowed($user, $expense->branch_id !== null ? (int) $expense->branch_id : null);
        }

        $posStaff = $route->parameter('posStaff');
        if ($posStaff instanceof PosStaff && $this->inTenant($posStaff->company_id, $tenantId)) {
            $this->ensureAllowed($user, (int) $posStaff->branch_id);
        }

        $transfer = $route->parameter('transfer');
        if ($transfer instanceof BranchTransfer && $this->inTenant($transfer->company_id, $tenantId)) {
            if (! $user->canAccessBranchId((int) $transfer->from_branch_id)
                && ! $user->canAccessBranchId((int) $transfer->to_branch_id)) {
                abort(403, 'Your account is restricted to specific branches.');
            }
        }

        $restockRequest = $route->parameter('restockRequest');
        if ($restockRequest instanceof RestockRequest && $this->inTenant($restockRequest->company_id, $tenantId)) {
            $this->ensureAllowed($user, (int) $restockRequest->branch_id);
        }
    }

    private function inTenant(int|string|null $companyId, ?int $tenantId): bool
    {
        return $tenantId !== null && (int) $companyId === $tenantId;
    }

    private function ensureAllowed(User $user, ?int $branchId): void
    {
        if (! $user->canAccessBranchId($branchId)) {
            abort(403, 'Your account is restricted to specific branches.');
        }
    }
}
