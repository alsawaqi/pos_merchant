<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\RestockRequestStatus;
use App\Models\RestockRequest;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5c — HQ review step: Submitted → Approved | Rejected.
 *
 * Approve + Reject share a single action because they:
 *   - gate on the same permission (inventory.restock_request.review)
 *   - require the same actor + tenant checks
 *   - both populate reviewed_by + reviewed_at
 *   - differ only in the new status + the note requirement
 *
 * Rejection mandates a non-empty review_note ("we're out too" /
 * "wait til next month" / etc.) so the requester gets context.
 * Approval can carry an optional note.
 *
 * Source state MUST be Submitted — Draft can't be reviewed
 * (nothing to review yet), Approved/Fulfilled/Rejected/Cancelled
 * are all terminal or post-review.
 *
 * Approved → Fulfilled is handled by AllocateRestockRequestAction
 * (a separate Action because it writes stock movements, which
 * is a different blast radius than a pure status flip).
 */
final readonly class ReviewRestockRequestAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function approve(RestockRequest $request, User $actor, ?string $note = null): RestockRequest
    {
        return $this->transition($request, $actor, RestockRequestStatus::Approved, $note);
    }

    public function reject(RestockRequest $request, User $actor, string $note): RestockRequest
    {
        if (trim($note) === '') {
            throw new RuntimeException('A rejection note is required so the requester knows why.');
        }
        return $this->transition($request, $actor, RestockRequestStatus::Rejected, $note);
    }

    private function transition(
        RestockRequest $request,
        User $actor,
        RestockRequestStatus $newStatus,
        ?string $note,
    ): RestockRequest {
        $companyId = $this->tenant->requiredId();
        if ((int) $request->company_id !== $companyId) {
            abort(404);
        }

        if ($request->status !== RestockRequestStatus::Submitted) {
            throw new RuntimeException(sprintf(
                'Only Submitted requests can be reviewed (current status: %s).',
                $request->status->value,
            ));
        }

        return DB::transaction(function () use ($request, $actor, $newStatus, $note, $companyId): RestockRequest {
            $oldStatus = $request->status->value;
            $request->forceFill([
                'status' => $newStatus->value,
                'reviewed_by_user_id' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: $newStatus === RestockRequestStatus::Approved
                    ? 'inventory.restock_request.approved'
                    : 'inventory.restock_request.rejected',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $request->branch_id,
                auditableType: RestockRequest::class,
                auditableId: $request->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus->value, 'review_note' => $note],
            ));

            return $request->fresh(['lines.ingredient', 'branch']);
        });
    }
}
