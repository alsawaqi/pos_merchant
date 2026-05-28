<?php

declare(strict_types=1);

namespace App\Actions\Pos\Customers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Customer;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6a — soft-delete a customer.
 *
 * No future-order guard yet (orders don't ship until Phase 7+).
 * When orders land, this action will gain a check refusing to
 * delete a customer with any non-terminal order — same pattern
 * as DeleteIngredientAction's recipe + open-restock guards.
 *
 * Soft-delete preserves the row so:
 *   - vehicle plates (cascadeOnDelete on the FK) survive (the
 *     parent isn't physically gone)
 *   - future Phase 7+ order rows that reference customer_id
 *     can still resolve withTrashed() for historical reports
 *
 * Plates are not detached on customer soft-delete — the
 * (company_id, plate_number) unique constraint still blocks
 * re-using the plate elsewhere. The merchant can manually
 * detach if they really want to re-issue the plate to another
 * customer.
 */
final readonly class DeleteCustomerAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Customer $customer, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $customer->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($customer, $actor, $companyId): void {
            $customerId = $customer->id;
            $snapshot = [
                'name' => $customer->name,
                'phone' => $customer->phone,
            ];

            $customer->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'customers.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Customer::class,
                auditableId: $customerId,
                oldValues: $snapshot,
            ));
        });
    }
}
