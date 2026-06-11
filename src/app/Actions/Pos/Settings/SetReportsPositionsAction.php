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
 * P-F6 — set which staff positions may open the Reports dashboard on the
 * POS device (branch sales / tenders / top products / consumption).
 *
 * Upserts the company's `reports_positions` row in pos_company_settings
 * (one row per company+key). pos_api emits this list in /device/config and
 * the DEVICE gates its Reports screen on it. Audited
 * (settings.reports_positions.updated) so widening who can read branch
 * revenue figures stays traceable.
 */
final readonly class SetReportsPositionsAction
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
                'key' => CompanySetting::KEY_REPORTS_POSITIONS,
            ]);

            $old = is_array($setting->value) ? array_values($setting->value) : [];
            $setting->value = $positions;
            $setting->save();

            if ($old !== $positions) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.reports_positions.updated',
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
