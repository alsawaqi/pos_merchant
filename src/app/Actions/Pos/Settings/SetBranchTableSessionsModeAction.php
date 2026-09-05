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

/** Branch-only rollout policy: off is explicit, never company inheritance. */
final readonly class SetBranchTableSessionsModeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Branch $branch, string $mode, User $actor): string
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }
        if (! in_array($mode, ['off', 'shadow', 'live'], true)) {
            throw new InvalidArgumentException('Invalid table sessions mode.');
        }

        return DB::transaction(function () use ($companyId, $branch, $mode, $actor): string {
            $setting = BranchSetting::query()->firstOrNew([
                'branch_id' => $branch->id, 'key' => 'table_sessions_mode',
            ], ['company_id' => $companyId]);
            $value = $setting->value;
            $old = is_string($value) && in_array($value, ['off', 'shadow', 'live'], true) ? $value : null;
            if ($setting->exists && $old === $mode) {
                return $mode;
            }
            $setting->value = $mode;
            $setting->save();
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.table_sessions_mode.branch_updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: Branch::class,
                auditableId: $branch->id,
                oldValues: ['table_sessions_mode' => $old],
                newValues: ['table_sessions_mode' => $mode],
            ));

            return $mode;
        });
    }
}
