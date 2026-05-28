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
 * Loyalty refactor — update a loyalty rule.
 *
 * Editable: name, config_json, validity window, status. The rule
 * TYPE is immutable — changing visit_based ↔ spend_based would
 * invalidate every existing account's balance semantics, so a
 * type change means a new rule.
 *
 * Audit event: loyalty.rule.updated.
 */
final readonly class UpdateLoyaltyRuleAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(LoyaltyRule $rule, array $attributes, User $actor): LoyaltyRule
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $rule->company_id !== $companyId) {
            throw new RuntimeException('Loyalty rule does not belong to this company.');
        }

        $update = [];
        if (array_key_exists('name', $attributes)) {
            $name = trim((string) $attributes['name']);
            if ($name === '') {
                throw new RuntimeException('Loyalty rule name cannot be empty.');
            }
            $update['name'] = $name;
        }
        if (array_key_exists('config_json', $attributes)) {
            $update['config_json'] = $attributes['config_json'];
        }
        if (array_key_exists('validity_start', $attributes)) {
            $update['validity_start'] = $attributes['validity_start'];
        }
        if (array_key_exists('validity_end', $attributes)) {
            $update['validity_end'] = $attributes['validity_end'];
        }
        if (array_key_exists('status', $attributes)) {
            $update['status'] = LoyaltyRuleStatus::from((string) $attributes['status'])->value;
        }

        $start = $update['validity_start'] ?? $rule->validity_start;
        $end = $update['validity_end'] ?? $rule->validity_end;
        if ($start !== null && $end !== null && $end <= $start) {
            throw new RuntimeException('validity_end must be after validity_start.');
        }

        return DB::transaction(function () use ($rule, $update, $actor, $companyId): LoyaltyRule {
            $before = ['name' => $rule->name, 'status' => $rule->status?->value];
            $rule->update($update);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.rule.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: LoyaltyRule::class,
                auditableId: $rule->id,
                oldValues: $before,
                newValues: ['name' => $rule->name, 'status' => $rule->status?->value],
            ));

            return $rule->fresh();
        });
    }
}
