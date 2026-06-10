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
 *
 * Phase D3 — date_of_birth + tags join the mutable set. The
 * API payload carries `tags`; it is normalised (trim, dedupe
 * case-insensitively, empty → NULL) and mapped to tags_json
 * before the diff. The diff itself compares CANONICAL values:
 * the model's date cast returns Carbon, so dob is compared as
 * a Y-m-d string or the strict === would always report a
 * change and write a bogus audit row.
 */
final readonly class UpdateCustomerAction
{
    private const MUTABLE_FIELDS = ['name', 'phone', 'date_of_birth', 'tags_json'];

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
        if (array_key_exists('date_of_birth', $attributes)) {
            $attributes['date_of_birth'] = $attributes['date_of_birth'] === null
                ? null
                : (string) $attributes['date_of_birth'];
        }
        // API payload key is `tags`; the column is tags_json.
        if (array_key_exists('tags', $attributes)) {
            $attributes['tags_json'] = $this->normaliseTags($attributes['tags']);
            unset($attributes['tags']);
        }

        return DB::transaction(function () use ($customer, $attributes, $actor, $companyId): Customer {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $current = $this->canonical($customer, $field);
                if ($current === $attributes[$field]) {
                    continue;
                }
                $changes[$field] = ['old' => $current, 'new' => $attributes[$field]];
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

    /**
     * The on-disk value in the same shape the payload uses, so the
     * strict === diff means "actually different": Carbon → Y-m-d
     * string for date_of_birth; arrays pass through (the payload
     * side is already normalised, so order is canonical).
     */
    private function canonical(Customer $customer, string $field): mixed
    {
        if ($field === 'date_of_birth') {
            return $customer->date_of_birth?->toDateString();
        }

        return $customer->{$field};
    }

    /**
     * Same normalisation as CreateCustomerAction: trim, drop
     * empties, case-insensitive dedupe (first casing wins),
     * empty set → NULL.
     *
     * @param  array<int, string>|null  $tags
     * @return array<int, string>|null
     */
    private function normaliseTags(?array $tags): ?array
    {
        if ($tags === null) {
            return null;
        }

        $clean = [];
        foreach ($tags as $tag) {
            $trimmed = trim((string) $tag);
            if ($trimmed === '') {
                continue;
            }
            $key = mb_strtolower($trimmed);
            if (! array_key_exists($key, $clean)) {
                $clean[$key] = $trimmed;
            }
        }

        return $clean === [] ? null : array_values($clean);
    }
}
