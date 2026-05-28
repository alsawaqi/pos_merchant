<?php

declare(strict_types=1);

namespace App\Actions\Pos\Customers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CustomerVehiclePlate;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6a — detach (hard-delete) a vehicle plate.
 *
 * No soft delete on plates — the audit log captures the
 * attach/detach lifecycle and the row itself is cheap to
 * recreate. Hard-deletion frees the plate number for re-
 * attachment to a different customer in the same merchant's
 * book (subject to the (company_id, plate_number) unique
 * constraint).
 */
final readonly class DetachVehiclePlateAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(CustomerVehiclePlate $plate, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $plate->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($plate, $actor, $companyId): void {
            $plateId = $plate->id;
            $snapshot = [
                'customer_id' => $plate->customer_id,
                'plate_number' => $plate->plate_number,
            ];

            $plate->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'customers.plate.detached',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: CustomerVehiclePlate::class,
                auditableId: $plateId,
                oldValues: $snapshot,
            ));
        });
    }
}
