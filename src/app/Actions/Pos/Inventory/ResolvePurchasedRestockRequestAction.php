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
 * Phase A (owner decision 2026-07-28) — close an Approved restock
 * request whose shortage was resolved by a PURCHASE rather than by
 * sending goods from the central warehouse: the branch bought outside
 * (supermarket run, recorded via the branch purchase flow) or a
 * supplier delivered directly to the branch.
 *
 * Deliberately writes NO stock movements and leaves every line's
 * quantity_allocated at 0 — the stock entered the books through the
 * purchase record itself, and crediting it here too would count the
 * goods twice. The request just transitions Approved → Fulfilled with
 * resolution='purchase' and an optional free-text note pointing at
 * the purchase ("GRN #123", "bought at Nesto").
 *
 * The warehouse path is {@see AllocateRestockRequestAction}
 * (resolution='warehouse', paired central-debit + branch-credit).
 *
 * Audit event: inventory.restock_request.resolved_purchased.
 */
final readonly class ResolvePurchasedRestockRequestAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(RestockRequest $request, ?string $note, User $actor): RestockRequest
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $request->company_id !== $companyId) {
            abort(404);
        }

        if ($request->status !== RestockRequestStatus::Approved) {
            throw new RuntimeException(sprintf(
                'Only Approved requests can be closed as purchased (current status: %s).',
                $request->status->value,
            ));
        }

        return DB::transaction(function () use ($request, $note, $actor, $companyId): RestockRequest {
            $oldStatus = $request->status->value;

            $request->forceFill([
                'status' => RestockRequestStatus::Fulfilled->value,
                'fulfilled_at' => now(),
                'resolution' => 'purchase',
                'resolution_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.restock_request.resolved_purchased',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $request->branch_id,
                auditableType: RestockRequest::class,
                auditableId: $request->id,
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => RestockRequestStatus::Fulfilled->value,
                    'resolution' => 'purchase',
                    'resolution_note' => $request->resolution_note,
                ],
            ));

            return $request->fresh(['lines.ingredient', 'branch']);
        });
    }
}
