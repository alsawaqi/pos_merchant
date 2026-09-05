<?php

declare(strict_types=1);

namespace App\Actions\Pos\Settings;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CompanySetting;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Set the company default for dine-in QR rounds. Existing company rows
 * remain in place; branches without an override inherit this JSON string.
 * Audit only changes, tolerating malformed values previously written raw.
 */
final readonly class SetDineInRoundModeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(string $mode, User $actor): string
    {
        if (! in_array($mode, ['kitchen_direct', 'staff_confirm'], true)) {
            throw new InvalidArgumentException('Invalid dine-in round mode.');
        }

        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($companyId, $mode, $actor): string {
            $setting = CompanySetting::query()->firstOrNew([
                'company_id' => $companyId,
                'key' => CompanySetting::KEY_DINE_IN_ROUND_MODE,
            ]);

            // The Eloquent JSON cast already decodes the scalar once.
            $value = $setting->value;
            $old = is_string($value) && in_array($value, ['kitchen_direct', 'staff_confirm'], true)
                ? $value
                : null;

            if ($old === $mode) {
                return $mode;
            }

            $setting->value = $mode;
            $setting->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.dine_in_round_mode.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: CompanySetting::class,
                auditableId: $setting->id,
                oldValues: ['dine_in_round_mode' => $old],
                newValues: ['dine_in_round_mode' => $mode],
            ));

            return $mode;
        });
    }
}
