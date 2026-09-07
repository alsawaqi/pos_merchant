<?php

declare(strict_types=1);

namespace App\Actions\Pos\Settings;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SetBranchQrTableCardSettingsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Branch $branch, string $enabled, string $geofence, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        abort_if((int) $branch->company_id !== $companyId, 404);
        if (! in_array($enabled, ['off', 'on'], true)
            || ! in_array($geofence, ['off', 'advisory', 'enforce'], true)) {
            throw new InvalidArgumentException('Invalid QR table card settings.');
        }

        DB::transaction(function () use ($companyId, $branch, $enabled, $geofence, $actor): void {
            Branch::query()->where('company_id', $companyId)->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $new = ['qr_table_card_enabled' => $enabled, 'qr_scan_geofence_mode' => $geofence];
            $old = [];
            $changed = false;
            foreach ($new as $key => $value) {
                $setting = BranchSetting::query()->firstOrNew([
                    'company_id' => $companyId, 'branch_id' => $branch->id, 'key' => $key,
                ]);
                $allowed = $key === 'qr_table_card_enabled' ? ['off', 'on'] : ['off', 'advisory', 'enforce'];
                $old[$key] = is_string($setting->value) && in_array($setting->value, $allowed, true)
                    ? $setting->value : null;
                if (! $setting->exists || $old[$key] !== $value) {
                    $setting->value = $value;
                    $setting->save();
                    $changed = true;
                }
            }
            if (! $changed) {
                return;
            }
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.qr_table_cards.branch_updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: Branch::class,
                auditableId: $branch->id,
                oldValues: $old,
                newValues: $new,
            ));
        });
    }
}
