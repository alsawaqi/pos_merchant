<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\LoyaltyRuleStatus;
use App\Enums\LoyaltyRuleType;
use App\Models\LoyaltyRule;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loyalty refactor — create a loyalty rule.
 *
 * Validation:
 *   - name non-empty
 *   - type in LoyaltyRuleType
 *   - validity_end > validity_start (when both set)
 *
 * config_json shape is validated by the form request per type;
 * the Action stores it as given. Status defaults to active.
 *
 * Audit event: loyalty.rule.created.
 */
final readonly class CreateLoyaltyRuleAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): LoyaltyRule
    {
        $companyId = $this->tenant->requiredId();

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Loyalty rule name is required.');
        }

        $type = LoyaltyRuleType::from((string) $attributes['type']);

        $start = $attributes['validity_start'] ?? null;
        $end = $attributes['validity_end'] ?? null;
        if ($start !== null && $end !== null && $end <= $start) {
            throw new RuntimeException('validity_end must be after validity_start.');
        }

        return DB::transaction(function () use ($attributes, $name, $type, $start, $end, $actor, $companyId): LoyaltyRule {
            /** @var LoyaltyRule $rule */
            $rule = LoyaltyRule::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'type' => $type->value,
                'config_json' => $attributes['config_json'] ?? [],
                'validity_start' => $start,
                'validity_end' => $end,
                'status' => $attributes['status'] ?? LoyaltyRuleStatus::Active->value,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.rule.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: LoyaltyRule::class,
                auditableId: $rule->id,
                newValues: ['name' => $name, 'type' => $type->value],
            ));

            return $rule->fresh();
        });
    }
}
