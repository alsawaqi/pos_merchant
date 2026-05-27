<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Supplier;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5a — create a supplier for the actor's company.
 *
 * Audit event: inventory.supplier.created.
 */
final readonly class CreateSupplierAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, contact?: string|null, notes?: string|null}  $attributes
     */
    public function handle(array $attributes, User $actor): Supplier
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($attributes, $actor, $companyId): Supplier {
            /** @var Supplier $supplier */
            $supplier = Supplier::query()->create([
                'company_id' => $companyId,
                'name' => $attributes['name'],
                'contact' => $attributes['contact'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => 'active',
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.supplier.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Supplier::class,
                auditableId: $supplier->id,
                newValues: ['name' => $supplier->name],
            ));

            return $supplier;
        });
    }
}
