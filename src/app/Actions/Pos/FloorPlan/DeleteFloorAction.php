<?php

declare(strict_types=1);

namespace App\Actions\Pos\FloorPlan;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Floor;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Soft-delete a floor.
 *
 * Refuses if the floor has ANY non-trashed tables — deleting
 * would cascade them away (the FK is cascadeOnDelete) and
 * silently break references from historical orders. Forces
 * the merchant to either move the tables or delete them
 * first.
 *
 * Audit event: floor.deleted with a snapshot of the floor's
 * state at delete time.
 */
final readonly class DeleteFloorAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Floor $floor, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $floor->company_id !== $companyId) {
            abort(404);
        }

        $tableCount = $floor->tables()->count();
        if ($tableCount > 0) {
            throw new RuntimeException(
                sprintf(
                    'This floor has %d table(s). Delete or move them first.',
                    $tableCount,
                ),
            );
        }

        DB::transaction(function () use ($floor, $actor, $companyId): void {
            $snapshot = [
                'name' => $floor->name,
                'name_ar' => $floor->name_ar,
                'branch_id' => $floor->branch_id,
            ];
            $floorId = $floor->id;
            $branchId = $floor->branch_id;

            $floor->delete(); // soft delete

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'floor.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branchId,
                auditableType: Floor::class,
                auditableId: $floorId,
                oldValues: $snapshot,
            ));
        });
    }
}
