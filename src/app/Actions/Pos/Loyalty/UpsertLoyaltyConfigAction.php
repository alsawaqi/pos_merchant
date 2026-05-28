<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CustomerLoyaltyConfig;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6b — create or update the per-company loyalty config.
 *
 * Singleton row per company. First call creates; subsequent
 * calls partial-update with diff-aware audit (mirrors the
 * UpdateSupplierAction / UpdateCustomerAction pattern).
 *
 * Both rates must be >= 0. points_per_omr = 0 means "no
 * auto-earn" — a deliberate off-state without flipping
 * is_active. baisas_per_point = 0 would make points worthless
 * on redemption (also a deliberate off-state).
 *
 * Audit event: loyalty.config.updated (always — even on
 * first create, we want the event in the trail so reports
 * can show "merchant turned on loyalty on date X").
 */
final readonly class UpsertLoyaltyConfigAction
{
    private const MUTABLE_FIELDS = ['points_per_omr', 'baisas_per_point', 'is_active'];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{points_per_omr?: int, baisas_per_point?: int, is_active?: bool}  $attributes
     */
    public function handle(array $attributes, User $actor): CustomerLoyaltyConfig
    {
        $companyId = $this->tenant->requiredId();

        if (array_key_exists('points_per_omr', $attributes) && $attributes['points_per_omr'] < 0) {
            throw new RuntimeException('points_per_omr cannot be negative.');
        }
        if (array_key_exists('baisas_per_point', $attributes) && $attributes['baisas_per_point'] < 0) {
            throw new RuntimeException('baisas_per_point cannot be negative.');
        }

        return DB::transaction(function () use ($attributes, $actor, $companyId): CustomerLoyaltyConfig {
            /** @var CustomerLoyaltyConfig $config */
            $config = CustomerLoyaltyConfig::query()->firstOrCreate(
                ['company_id' => $companyId],
                [
                    'points_per_omr' => 0,
                    'baisas_per_point' => 10,
                    'is_active' => false,
                ],
            );

            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                // Cast booleans for fair comparison — Eloquent's
                // hydrated value is bool, the payload may be 0/1
                // or true/false.
                $newValue = $field === 'is_active'
                    ? (bool) $attributes[$field]
                    : (int) $attributes[$field];
                if ($config->{$field} === $newValue) {
                    continue;
                }
                $changes[$field] = ['old' => $config->{$field}, 'new' => $newValue];
                $config->{$field} = $newValue;
            }

            if ($changes === [] && $config->wasRecentlyCreated === false) {
                // No-op partial update on an existing config.
                return $config->fresh();
            }

            $config->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.config.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: CustomerLoyaltyConfig::class,
                auditableId: $config->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $config->fresh();
        });
    }
}
