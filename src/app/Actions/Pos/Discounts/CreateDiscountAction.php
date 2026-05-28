<?php

declare(strict_types=1);

namespace App\Actions\Pos\Discounts;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\DiscountAmountType;
use App\Enums\DiscountScope;
use App\Enums\DiscountStatus;
use App\Models\Discount;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6d — create a discount rule.
 *
 * Validation:
 *   - name non-empty
 *   - scope in DiscountScope enum
 *   - amount_type in DiscountAmountType enum
 *   - amount > 0; if amount_type=percent, amount <= 100
 *   - validity_end > validity_start (when both set)
 *
 * Status defaults to active; the request can override.
 * Targets are NOT attached here — Phase 6d's
 * SetDiscountTargetsAction is the dedicated path so the
 * create + targets-sync flow is composable.
 *
 * Audit event: catalogue.discount.created.
 */
final readonly class CreateDiscountAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): Discount
    {
        $companyId = $this->tenant->requiredId();

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Discount name is required.');
        }

        $scope = DiscountScope::from((string) $attributes['scope']);
        $amountType = DiscountAmountType::from((string) $attributes['amount_type']);
        $amount = (float) $attributes['amount'];

        if ($amount <= 0) {
            throw new RuntimeException('Discount amount must be positive.');
        }
        if ($amountType === DiscountAmountType::Percent && $amount > 100) {
            throw new RuntimeException('Percent discount cannot exceed 100.');
        }

        $start = $attributes['validity_start'] ?? null;
        $end = $attributes['validity_end'] ?? null;
        if ($start !== null && $end !== null && $end <= $start) {
            throw new RuntimeException('validity_end must be after validity_start.');
        }

        return DB::transaction(function () use ($attributes, $name, $scope, $amountType, $amount, $start, $end, $actor, $companyId): Discount {
            /** @var Discount $discount */
            $discount = Discount::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'scope' => $scope->value,
                'amount_type' => $amountType->value,
                'amount' => number_format($amount, 3, '.', ''),
                'validity_start' => $start,
                'validity_end' => $end,
                'dayofweek_mask' => $attributes['dayofweek_mask'] ?? null,
                'time_start' => $attributes['time_start'] ?? null,
                'time_end' => $attributes['time_end'] ?? null,
                'branch_scope_json' => $attributes['branch_scope_json'] ?? null,
                'stackable' => (bool) ($attributes['stackable'] ?? false),
                'requires_manager_approval' => (bool) ($attributes['requires_manager_approval'] ?? false),
                'status' => $attributes['status'] ?? DiscountStatus::Active->value,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.discount.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Discount::class,
                auditableId: $discount->id,
                newValues: [
                    'name' => $name,
                    'scope' => $scope->value,
                    'amount_type' => $amountType->value,
                    'amount' => (string) $discount->amount,
                ],
            ));

            return $discount->fresh();
        });
    }
}
