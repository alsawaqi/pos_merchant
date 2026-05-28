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
 * Phase 6d — pause a discount rule (active → paused).
 *
 * Dedicated Action so the audit event reads catalogue
 * .discount.paused (semantic clarity for reports) rather
 * than a generic catalogue.discount.updated.
 *
 * Refuses if the rule is already paused or expired -- a
 * silent no-op would mask a UI race.
 */
final readonly class PauseDiscountAction
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
        if ($discount->status !== DiscountStatus::Active) {
            throw new RuntimeException(sprintf(
                'Only active discounts can be paused (current status: %s).',
                $discount->status->value,
            ));
        }

        return DB::transaction(function () use ($discount, $actor, $companyId): Discount {
            $oldStatus = $discount->status->value;
            $discount->forceFill(['status' => DiscountStatus::Paused->value])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.discount.paused',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Discount::class,
                auditableId: $discount->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => DiscountStatus::Paused->value],
            ));

            return $discount->fresh();
        });
    }
}
