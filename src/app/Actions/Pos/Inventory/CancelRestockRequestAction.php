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
 * Phase 5c — requester cancels their request.
 *
 * Legal source states: Draft and Submitted only.
 *
 * Once HQ has Approved (or further advanced to Fulfilled), the
 * requester can no longer cancel — they have to either accept
 * what HQ committed to ship, or coordinate with HQ for a
 * rejection / refund. This mirrors the real-world expectation
 * that "approved" means HQ has already done physical work.
 *
 * Cancelled is terminal — the row stays in the table for the
 * audit trail; the UI just filters it from default views.
 */
final readonly class CancelRestockRequestAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(RestockRequest $request, User $actor, ?string $note = null): RestockRequest
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $request->company_id !== $companyId) {
            abort(404);
        }

        $legal = [RestockRequestStatus::Draft, RestockRequestStatus::Submitted];
        if (! in_array($request->status, $legal, true)) {
            throw new RuntimeException(sprintf(
                'Only Draft or Submitted requests can be cancelled (current status: %s).',
                $request->status->value,
            ));
        }

        return DB::transaction(function () use ($request, $actor, $note, $companyId): RestockRequest {
            $oldStatus = $request->status->value;
            $request->forceFill([
                'status' => RestockRequestStatus::Cancelled->value,
                // We reuse review_note as the cancellation reason
                // storage — keeps the schema lean (no need for a
                // separate cancellation_note column).
                'review_note' => $note,
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.restock_request.cancelled',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $request->branch_id,
                auditableType: RestockRequest::class,
                auditableId: $request->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => RestockRequestStatus::Cancelled->value, 'review_note' => $note],
            ));

            return $request->fresh(['lines.ingredient', 'branch']);
        });
    }
}
