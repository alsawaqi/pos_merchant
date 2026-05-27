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
 * Phase 5a — partial-update a supplier with diff-aware audit.
 */
final readonly class UpdateSupplierAction
{
    private const MUTABLE_FIELDS = ['name', 'contact', 'notes', 'status'];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Supplier $supplier, array $attributes, User $actor): Supplier
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $supplier->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($supplier, $attributes, $actor, $companyId): Supplier {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                if ($supplier->{$field} == $attributes[$field]) {
                    continue;
                }
                $changes[$field] = ['old' => $supplier->{$field}, 'new' => $attributes[$field]];
                $supplier->{$field} = $attributes[$field];
            }

            if ($changes === []) {
                return $supplier->fresh();
            }

            $supplier->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.supplier.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Supplier::class,
                auditableId: $supplier->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $supplier->fresh();
        });
    }
}
