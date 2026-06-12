<?php

declare(strict_types=1);

namespace App\Actions\Pos\Settings;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CompanySetting;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * P-G1 — set which staff positions may open the Kitchen production section
 * on the POS device (start / finish / view cooked-product batches).
 *
 * Upserts the company's `kitchen_positions` row in pos_company_settings
 * (one row per company+key). pos_api emits this list in /device/config and
 * the DEVICE gates its Kitchen screen on it — the exact reports_positions
 * pattern. Audited (settings.kitchen_positions.updated) so widening who can
 * consume ingredient stock stays traceable.
 */
final readonly class SetKitchenPositionsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  list<string>  $positions
     * @return list<string>  the persisted, normalised positions
     */
    public function handle(array $positions, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        $positions = array_values(array_unique(array_map('strval', $positions)));

        return DB::transaction(function () use ($companyId, $positions, $actor): array {
            $setting = CompanySetting::query()->firstOrNew([
                'company_id' => $companyId,
                'key' => CompanySetting::KEY_KITCHEN_POSITIONS,
            ]);

            $old = is_array($setting->value) ? array_values($setting->value) : [];
            $setting->value = $positions;
            $setting->save();

            if ($old !== $positions) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.kitchen_positions.updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: CompanySetting::class,
                    auditableId: $setting->id,
                    oldValues: ['positions' => $old],
                    newValues: ['positions' => $positions],
                ));
            }

            return $positions;
        });
    }
}
