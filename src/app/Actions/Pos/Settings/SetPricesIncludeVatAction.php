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
 * LAUNCH-P4 B1 — the merchant's "Menu prices include VAT" switch
 * (pos_company_settings key tax.prices_include_vat, default TRUE). Upserts the
 * row and audits a change. pos_api emits it to devices in company.tax.
 */
final readonly class SetPricesIncludeVatAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(bool $includeVat, User $actor): bool
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($companyId, $includeVat, $actor): bool {
            $old = CompanySetting::boolFor($companyId, CompanySetting::KEY_PRICES_INCLUDE_VAT, true);

            $setting = CompanySetting::query()->firstOrNew([
                'company_id' => $companyId,
                'key' => CompanySetting::KEY_PRICES_INCLUDE_VAT,
            ]);
            $setting->value = $includeVat;
            $setting->save();

            if ($old !== $includeVat) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.tax.prices_include_vat.updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: CompanySetting::class,
                    auditableId: $setting->id,
                    oldValues: ['prices_include_vat' => $old],
                    newValues: ['prices_include_vat' => $includeVat],
                ));
            }

            return $includeVat;
        });
    }
}
