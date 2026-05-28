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
 * Phase 6a — create a customer in the actor's company.
 *
 * Two writes in one transaction:
 *   1. The Customer row.
 *   2. The audit log entry (customers.created).
 *
 * Phone is normalised (trim) before write; the
 * (company_id, phone) unique constraint is the source of
 * truth for "is this phone already in the book". A duplicate
 * phone in the same tenant throws — the controller relays
 * as 422 ("a customer with this phone already exists").
 *
 * No plate creation here — plate attach is a separate Action.
 * The controller wraps "create customer + attach initial
 * plates" in its own transaction when the request payload
 * carries plates.
 */
final readonly class CreateCustomerAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, phone: string}  $attributes
     */
    public function handle(array $attributes, User $actor): Customer
    {
        $companyId = $this->tenant->requiredId();

        $name = trim((string) $attributes['name']);
        $phone = trim((string) $attributes['phone']);
        if ($name === '') {
            throw new RuntimeException('Customer name is required.');
        }
        if ($phone === '') {
            throw new RuntimeException('Customer phone is required.');
        }

        // Pre-flight duplicate check — surfaces a friendlier
        // error than the raw unique-constraint violation. The
        // DB constraint still backs us up under concurrent
        // writes.
        $duplicate = Customer::query()
            ->where('company_id', $companyId)
            ->where('phone', $phone)
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('A customer with this phone already exists.');
        }

        return DB::transaction(function () use ($name, $phone, $actor, $companyId): Customer {
            /** @var Customer $customer */
            $customer = Customer::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'phone' => $phone,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'customers.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Customer::class,
                auditableId: $customer->id,
                newValues: [
                    'name' => $name,
                    'phone' => $phone,
                ],
            ));

            return $customer->fresh();
        });
    }
}
