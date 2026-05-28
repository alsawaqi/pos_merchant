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
 * Phase 5c — transition Draft → Submitted.
 *
 * Only legal source state is Draft. Submission locks the lines
 * (UpdateRestockRequestAction refuses to edit a non-Draft
 * request) and starts the HQ review clock. Sets submitted_at.
 *
 * Refuses if the request has zero lines — a meaningful request
 * needs at least one ingredient.
 */
final readonly class SubmitRestockRequestAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(RestockRequest $request, User $actor): RestockRequest
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $request->company_id !== $companyId) {
            abort(404);
        }

        if ($request->status !== RestockRequestStatus::Draft) {
            throw new RuntimeException(sprintf(
                'Only Draft requests can be submitted (current status: %s).',
                $request->status->value,
            ));
        }

        if ($request->lines()->count() === 0) {
            throw new RuntimeException('Cannot submit an empty request — add at least one line first.');
        }

        return DB::transaction(function () use ($request, $actor, $companyId): RestockRequest {
            $oldStatus = $request->status->value;
            $request->forceFill([
                'status' => RestockRequestStatus::Submitted->value,
                'submitted_at' => now(),
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.restock_request.submitted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $request->branch_id,
                auditableType: RestockRequest::class,
                auditableId: $request->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => RestockRequestStatus::Submitted->value],
            ));

            return $request->fresh(['lines.ingredient', 'branch']);
        });
    }
}
