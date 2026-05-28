<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\LoyaltyRuleStatus;
use App\Models\LoyaltyRule;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loyalty refactor — resume a rule (paused → active).
 *
 * Audit event: loyalty.rule.resumed.
 */
final readonly class ResumeLoyaltyRuleAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(LoyaltyRule $rule, User $actor): LoyaltyRule
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $rule->company_id !== $companyId) {
            throw new RuntimeException('Loyalty rule does not belong to this company.');
        }

        return DB::transaction(function () use ($rule, $actor, $companyId): LoyaltyRule {
            $rule->update(['status' => LoyaltyRuleStatus::Active->value]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.rule.resumed',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: LoyaltyRule::class,
                auditableId: $rule->id,
                newValues: ['status' => LoyaltyRuleStatus::Active->value],
            ));

            return $rule->fresh();
        });
    }
}
