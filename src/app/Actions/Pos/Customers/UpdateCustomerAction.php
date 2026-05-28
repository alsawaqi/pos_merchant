<?php

declare(strict_types=1);

namespace App\Actions\Pos\Customers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Customer;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6a — partial-update a customer with diff-aware audit.
 *
 * Same idempotent pattern as UpdateSupplierAction: only the
 * fields actually included AND actually different from on-disk
 * get touched. If nothing changed, no DB write + no audit row.
 *
 * Phone uniqueness re-checked when phone is in the payload —
 * the (company_id, phone) constraint would catch it at the DB
 * level too, but a pre-flight check produces a cleaner error.
 */
final readonly class UpdateCustomerAction
{
    private const MUTABLE_FIELDS = ['name', 'phone'];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Customer $customer, array $attributes, User $actor): Customer
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $customer->company_id !== $companyId) {
            abort(404);
        }

        // Phone change → re-check uniqueness within tenant.
        if (array_key_exists('phone', $attributes)) {
            $newPhone = trim((string) $attributes['phone']);
            if ($newPhone === '') {
                throw new RuntimeException('Customer phone is required.');
            }
            $attributes['phone'] = $newPhone;
            if ($newPhone !== $customer->phone) {
                $duplicate = Customer::query()
                    ->where('company_id', $companyId)
                    ->where('phone', $newPhone)
                    ->where('id', '!=', $customer->id)
                    ->exists();
                if ($duplicate) {
                    throw new RuntimeException('Another customer with this phone already exists.');
                }
            }
        }
        if (array_key_exists('name', $attributes)) {
            $newName = trim((string) $attributes['name']);
            if ($newName === '') {
                throw new RuntimeException('Customer name is required.');
            }
            $attributes['name'] = $newName;
        }

        return DB::transaction(function () use ($customer, $attributes, $actor, $companyId): Customer {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                if ($customer->{$field} === $attributes[$field]) {
                    continue;
                }
                $changes[$field] = ['old' => $customer->{$field}, 'new' => $attributes[$field]];
                $customer->{$field} = $attributes[$field];
            }

            if ($changes === []) {
                return $customer->fresh();
            }

            $customer->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'customers.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Customer::class,
                auditableId: $customer->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $customer->fresh();
        });
    }
}
