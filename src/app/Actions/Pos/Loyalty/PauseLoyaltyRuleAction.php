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
 * Loyalty refactor — pause a rule (active → paused). Accounts +
 * balances are preserved; the rule simply stops applying at POS.
 *
 * Audit event: loyalty.rule.paused.
 */
final readonly class PauseLoyaltyRuleAction
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
            $rule->update(['status' => LoyaltyRuleStatus::Paused->value]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.rule.paused',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: LoyaltyRule::class,
                auditableId: $rule->id,
                newValues: ['status' => LoyaltyRuleStatus::Paused->value],
            ));

            return $rule->fresh();
        });
    }
}
