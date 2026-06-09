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
 * v2 #14 — set which staff positions may cancel a completed order at the POS.
 *
 * Upserts the company's `order_cancel_positions` row in pos_company_settings
 * (one row per company+key). pos_api emits this list in /device/config and the
 * device enforces it. Audited (settings.order_cancel_positions.updated) so the
 * change to a money-adjacent policy is traceable — a void reverses loyalty /
 * round-up / commission.
 */
final readonly class SetOrderCancelPositionsAction
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
                'key' => CompanySetting::KEY_ORDER_CANCEL_POSITIONS,
            ]);

            $old = is_array($setting->value) ? array_values($setting->value) : [];
            $setting->value = $positions;
            $setting->save();

            if ($old !== $positions) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.order_cancel_positions.updated',
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
