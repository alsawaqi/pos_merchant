<?php

declare(strict_types=1);

namespace App\Actions\Pos\FloorPlan;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Table;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete a table. The row stays so historical orders
 * that reference table_id can still resolve via
 * withTrashed(). Audit event: table.deleted.
 *
 * Unlike floor delete this isn't guarded by a "has children"
 * check — tables are leaf nodes; the only thing referencing
 * them is the future orders.table_id column, and that's
 * meant to survive a delete via the soft-delete pattern.
 */
final readonly class DeleteTableAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Table $table, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $table->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($table, $actor, $companyId): void {
            $snapshot = [
                'floor_id' => $table->floor_id,
                'label' => $table->label,
                'seats' => $table->seats,
            ];
            $tableId = $table->id;
            $branchId = $table->floor->branch_id;

            $table->delete(); // soft

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'table.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branchId,
                auditableType: Table::class,
                auditableId: $tableId,
                oldValues: $snapshot,
            ));
        });
    }
}
