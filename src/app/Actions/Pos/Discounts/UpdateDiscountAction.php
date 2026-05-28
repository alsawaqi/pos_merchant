<?php

declare(strict_types=1);

namespace App\Actions\Pos\Discounts;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\DiscountAmountType;
use App\Models\Discount;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6d — partial-update a discount with diff-aware audit.
 * Same pattern as UpdateSupplierAction / UpdateCustomerAction.
 *
 * Validation:
 *   - amount > 0 (if present); percent ≤ 100
 *   - validity_end > validity_start (when both reference a
 *     value, considering current row + payload)
 *
 * Idempotent: same shape in → no audit row written.
 *
 * Status edits are allowed here, but the Pause + Resume Actions
 * are cleaner for the common case (a single status flip + a
 * dedicated event name in the audit log).
 */
final readonly class UpdateDiscountAction
{
    private const MUTABLE_FIELDS = [
        'name', 'scope', 'amount_type', 'amount',
        'validity_start', 'validity_end',
        'dayofweek_mask', 'time_start', 'time_end',
        'branch_scope_json', 'stackable',
        'requires_manager_approval', 'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Discount $discount, array $attributes, User $actor): Discount
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $discount->company_id !== $companyId) {
            abort(404);
        }

        if (array_key_exists('name', $attributes)) {
            $name = trim((string) $attributes['name']);
            if ($name === '') {
                throw new RuntimeException('Discount name is required.');
            }
            $attributes['name'] = $name;
        }

        if (array_key_exists('amount', $attributes)) {
            $amount = (float) $attributes['amount'];
            if ($amount <= 0) {
                throw new RuntimeException('Discount amount must be positive.');
            }
            $amountType = array_key_exists('amount_type', $attributes)
                ? DiscountAmountType::from((string) $attributes['amount_type'])
                : $discount->amount_type;
            if ($amountType === DiscountAmountType::Percent && $amount > 100) {
                throw new RuntimeException('Percent discount cannot exceed 100.');
            }
            $attributes['amount'] = number_format($amount, 3, '.', '');
        }

        // Validate the combined validity window using current
        // values overlaid with the payload.
        $start = array_key_exists('validity_start', $attributes)
            ? $attributes['validity_start']
            : $discount->validity_start;
        $end = array_key_exists('validity_end', $attributes)
            ? $attributes['validity_end']
            : $discount->validity_end;
        if ($start !== null && $end !== null && $end <= $start) {
            throw new RuntimeException('validity_end must be after validity_start.');
        }

        return DB::transaction(function () use ($discount, $attributes, $actor, $companyId): Discount {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = match ($field) {
                    'stackable', 'requires_manager_approval' => (bool) $attributes[$field],
                    'dayofweek_mask' => $attributes[$field] === null ? null : (int) $attributes[$field],
                    default => $attributes[$field],
                };

                $current = $discount->{$field};
                // Cast enum + datetime to comparable form.
                $currentScalar = match (true) {
                    $current instanceof \BackedEnum => $current->value,
                    $current instanceof \DateTimeInterface => $current->format('Y-m-d H:i:s'),
                    is_bool($current) => $current,
                    default => $current,
                };
                $newScalar = match (true) {
                    $newValue instanceof \BackedEnum => $newValue->value,
                    $newValue instanceof \DateTimeInterface => $newValue->format('Y-m-d H:i:s'),
                    default => $newValue,
                };
                if ($currentScalar == $newScalar) {
                    continue;
                }
                $changes[$field] = ['old' => $currentScalar, 'new' => $newScalar];
                $discount->{$field} = $newValue;
            }

            if ($changes === []) {
                return $discount->fresh();
            }

            $discount->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.discount.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Discount::class,
                auditableId: $discount->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $discount->fresh();
        });
    }
}
