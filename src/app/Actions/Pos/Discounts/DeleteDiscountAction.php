<?php

declare(strict_types=1);

namespace App\Actions\Pos\Discounts;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Discount;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6d — soft-delete a discount rule.
 *
 * Targets survive the soft-delete (the FK cascadeOnDelete only
 * fires on a HARD delete). Phase 7+ historical orders will
 * still resolve the rule via withTrashed() for the §5.11.7
 * Discount Report.
 *
 * No future-order guard yet (orders arrive in Phase 7+). When
 * they do, this gains a check refusing to soft-delete a
 * discount with non-terminal order references.
 */
final readonly class DeleteDiscountAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Discount $discount, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $discount->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($discount, $actor, $companyId): void {
            $discountId = $discount->id;
            $snapshot = [
                'name' => $discount->name,
                'scope' => $discount->scope?->value,
                'amount_type' => $discount->amount_type?->value,
                'amount' => (string) $discount->amount,
            ];

            $discount->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.discount.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Discount::class,
                auditableId: $discountId,
                oldValues: $snapshot,
            ));
        });
    }
}
