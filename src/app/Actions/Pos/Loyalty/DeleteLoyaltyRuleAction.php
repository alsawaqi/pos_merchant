<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\LoyaltyRule;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loyalty refactor — soft-delete a rule. Accounts +
 * transactions are kept (historical reports + the audit trail);
 * the rule just disappears from the POS picker + config list.
 *
 * Audit event: loyalty.rule.deleted.
 */
final readonly class DeleteLoyaltyRuleAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(LoyaltyRule $rule, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $rule->company_id !== $companyId) {
            throw new RuntimeException('Loyalty rule does not belong to this company.');
        }

        DB::transaction(function () use ($rule, $actor, $companyId): void {
            $ruleId = $rule->id;
            $rule->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.rule.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: LoyaltyRule::class,
                auditableId: $ruleId,
                oldValues: ['name' => $rule->name],
            ));
        });
    }
}
