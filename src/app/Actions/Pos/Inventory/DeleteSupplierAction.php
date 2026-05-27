<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5a — soft-delete a supplier.
 *
 * Refuses if any non-deleted ingredient still names this
 * supplier as primary. Forces the merchant to either re-
 * assign those ingredients OR clear the supplier first.
 *
 * Historical stock_movements that referenced this supplier
 * are not affected — the polymorphic morphTo will return NULL
 * once the supplier is soft-deleted, which the reporting
 * layer renders as "supplier no longer on file".
 */
final readonly class DeleteSupplierAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Supplier $supplier, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $supplier->company_id !== $companyId) {
            abort(404);
        }

        $ingredientCount = Ingredient::query()
            ->where('primary_supplier_id', $supplier->id)
            ->count();
        if ($ingredientCount > 0) {
            throw new RuntimeException(sprintf(
                'Cannot delete supplier — %d ingredient(s) still name them as primary. Re-assign those first.',
                $ingredientCount,
            ));
        }

        DB::transaction(function () use ($supplier, $actor, $companyId): void {
            $supplierId = $supplier->id;
            $snapshot = ['name' => $supplier->name];
            $supplier->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.supplier.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Supplier::class,
                auditableId: $supplierId,
                oldValues: $snapshot,
            ));
        });
    }
}
