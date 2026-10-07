<?php

declare(strict_types=1);

namespace App\Actions\Pos\Costs;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CompanySetting;
use App\Models\User;
use App\Support\Costs\CostSettings;
use App\Support\Costs\FoodCost;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH costs & allergens add-on — save the price-alert threshold and / or
 * the company target food cost % (pos_company_settings, {@see CostSettings}).
 * Only the keys given change; a change is audited (settings.costs.updated)
 * with the old and new values.
 */
final readonly class SetCostSettingsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{price_alert_threshold_percent?: string|float|int, target_food_cost_percent?: string|float|int}  $values
     * @return array{price_alert_threshold_percent: string, target_food_cost_percent: string}
     */
    public function handle(array $values, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        $keys = [
            'price_alert_threshold_percent' => CostSettings::KEY_THRESHOLD,
            'target_food_cost_percent' => CostSettings::KEY_TARGET,
        ];

        return DB::transaction(function () use ($companyId, $values, $actor, $keys): array {
            $old = self::current($companyId);
            foreach ($keys as $field => $key) {
                if (! array_key_exists($field, $values)) {
                    continue;
                }
                $setting = CompanySetting::query()->withoutGlobalScopes()->firstOrNew(['company_id' => $companyId, 'key' => $key]);
                $setting->value = (float) CostSettings::percent($values[$field]);
                $setting->save();
            }
            $new = self::current($companyId);
            if ($new !== $old) {
                $changed = array_keys(array_diff_assoc($new, $old));
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.costs.updated',
                    actorUserId: (int) $actor->getKey(),
                    companyId: $companyId,
                    auditableType: CompanySetting::class,
                    oldValues: array_intersect_key($old, array_flip($changed)),
                    newValues: array_intersect_key($new, array_flip($changed)),
                ));
                FoodCost::forget($companyId);
            }

            return $new;
        });
    }

    /** @return array{price_alert_threshold_percent: string, target_food_cost_percent: string} */
    public static function current(int $companyId): array
    {
        return [
            'price_alert_threshold_percent' => CostSettings::threshold($companyId),
            'target_food_cost_percent' => CostSettings::target($companyId),
        ];
    }
}
