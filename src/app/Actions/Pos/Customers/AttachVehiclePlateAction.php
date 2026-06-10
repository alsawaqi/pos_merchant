<?php

declare(strict_types=1);

namespace App\Actions\Pos\Customers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6a — attach a vehicle plate to a customer.
 *
 * Plate normalisation: trim + collapse internal whitespace
 * to single space + uppercase. This makes "12345  a" and
 * " 12345 A " resolve to the same canonical "12345 A" so
 * the (company_id, customer_id, plate_number) link unique
 * catches near-duplicates the merchant might enter by accident.
 *
 * P-F2 — plates are many-to-many: the same plate CAN belong to
 * several customers in the same company (family car shared by
 * several loyalty members). The only duplicate is re-attaching
 * a plate THIS customer already holds.
 *
 * Duplicate handling: a pre-flight existence check produces
 * a clean "plate already attached" error before the DB-level
 * unique-constraint violation would. The constraint still
 * backs us up under concurrent writes.
 *
 * The plate ALWAYS belongs to the same company as the parent
 * customer — we denormalise on insert so the unique constraint
 * works without a join through customers.
 */
final readonly class AttachVehiclePlateAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Customer $customer, string $plateNumber, User $actor): CustomerVehiclePlate
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $customer->company_id !== $companyId) {
            abort(404);
        }

        $normalised = $this->normalise($plateNumber);
        if ($normalised === '') {
            throw new RuntimeException('Plate number is required.');
        }

        $duplicate = CustomerVehiclePlate::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('plate_number', $normalised)
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('This plate is already attached to this customer.');
        }

        return DB::transaction(function () use ($customer, $normalised, $actor, $companyId): CustomerVehiclePlate {
            /** @var CustomerVehiclePlate $plate */
            $plate = CustomerVehiclePlate::query()->create([
                'customer_id' => $customer->id,
                'company_id' => $companyId,
                'plate_number' => $normalised,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'customers.plate.attached',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: CustomerVehiclePlate::class,
                auditableId: $plate->id,
                newValues: [
                    'customer_id' => $customer->id,
                    'plate_number' => $normalised,
                ],
            ));

            return $plate->fresh();
        });
    }

    private function normalise(string $raw): string
    {
        // Trim, collapse internal whitespace, uppercase.
        return strtoupper(preg_replace('/\s+/', ' ', trim($raw)) ?? '');
    }
}
