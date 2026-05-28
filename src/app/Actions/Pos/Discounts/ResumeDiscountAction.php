<?php

declare(strict_types=1);

namespace App\Actions\Pos\Discounts;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\DiscountStatus;
use App\Models\Discount;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6d — resume a paused discount (paused → active).
 *
 * Refuses if the rule is active or expired — same rationale
 * as PauseDiscountAction.
 */
final readonly class ResumeDiscountAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Discount $discount, User $actor): Discount
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $discount->company_id !== $companyId) {
            abort(404);
        }
        if ($discount->status !== DiscountStatus::Paused) {
            throw new RuntimeException(sprintf(
                'Only paused discounts can be resumed (current status: %s).',
                $discount->status->value,
            ));
        }

        return DB::transaction(function () use ($discount, $actor, $companyId): Discount {
            $oldStatus = $discount->status->value;
            $discount->forceFill(['status' => DiscountStatus::Active->value])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.discount.resumed',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Discount::class,
                auditableId: $discount->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => DiscountStatus::Active->value],
            ));

            return $discount->fresh();
        });
    }
}
