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
 * PT — set the per-company purchase_tax_recoverable flag. When ON, the Sales
 * report credits the tracked purchase tax back into net profit (a reclaimable
 * VAT receivable); when OFF (default) the tax is informational only. Upserts the
 * pos_company_settings row + writes an audit trail. Mirrors
 * {@see SetKitchenPositionsAction}.
 */
final readonly class SetPurchaseTaxRecoverableAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(bool $recoverable, User $actor): bool
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($companyId, $recoverable, $actor): bool {
            $setting = CompanySetting::query()->firstOrNew([
                'company_id' => $companyId,
                'key' => CompanySetting::KEY_PURCHASE_TAX_RECOVERABLE,
            ]);

            $old = (bool) $setting->value;
            $setting->value = $recoverable;
            $setting->save();

            if ($old !== $recoverable) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.purchase_tax_recoverable.updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: CompanySetting::class,
                    auditableId: $setting->id,
                    oldValues: ['purchase_tax_recoverable' => $old],
                    newValues: ['purchase_tax_recoverable' => $recoverable],
                ));
            }

            return $recoverable;
        });
    }
}
