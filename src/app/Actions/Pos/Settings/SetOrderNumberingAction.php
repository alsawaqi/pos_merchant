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
 * P-F8 — set the merchant's order numbering policy.
 *
 * Upserts the company's `order_numbering` row in pos_company_settings
 * (one row per company+key) with the normalised five-key shape
 * {enabled, prefix, pad, scope, daily_reset}. pos_api emits the block in
 * /device/config and allocates the actual numbers atomically on
 * POST /device/orders/next-number (pos_order_sequences). Audited
 * (settings.order_numbering.updated) — receipt numbers are a customer-
 * facing, dispute-relevant artefact, so policy flips must be traceable.
 */
final readonly class SetOrderNumberingAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{enabled: bool, prefix: string, pad: int, scope: string, daily_reset: bool}  $value
     * @return array{enabled: bool, prefix: string, pad: int, scope: string, daily_reset: bool}
     */
    public function handle(array $value, User $actor): array
    {
        $companyId = $this->tenant->requiredId();

        // Persist exactly the five known keys, normalised (the request
        // already validated shape/bounds; this is belt-and-braces so a
        // stray extra key can never ride into the device config).
        $value = [
            'enabled' => (bool) $value['enabled'],
            'prefix' => mb_substr(trim((string) $value['prefix']), 0, 8),
            'pad' => max(3, min(6, (int) $value['pad'])),
            'scope' => $value['scope'] === 'company' ? 'company' : 'branch',
            'daily_reset' => (bool) $value['daily_reset'],
        ];

        return DB::transaction(function () use ($companyId, $value, $actor): array {
            $setting = CompanySetting::query()->firstOrNew([
                'company_id' => $companyId,
                'key' => CompanySetting::KEY_ORDER_NUMBERING,
            ]);

            $old = is_array($setting->value) ? $setting->value : [];
            $setting->value = $value;
            $setting->save();

            if ($old !== $value) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.order_numbering.updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: CompanySetting::class,
                    auditableId: $setting->id,
                    oldValues: ['order_numbering' => $old],
                    newValues: ['order_numbering' => $value],
                ));
            }

            return $value;
        });
    }
}
