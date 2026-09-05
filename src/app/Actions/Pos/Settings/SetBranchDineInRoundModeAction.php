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

/**
 * Set one branch's round policy; null restores inheritance by deleting the
 * row. Company/branch consistency is enforced here, above the two FKs.
 * Audit the branch itself so inheritance never points at a deleted setting.
 */
final readonly class SetBranchDineInRoundModeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Branch $branch, ?string $mode, User $actor): ?string
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }

        if ($mode !== null && ! in_array($mode, ['kitchen_direct', 'staff_confirm'], true)) {
            throw new InvalidArgumentException('Invalid dine-in round mode.');
        }

        return DB::transaction(function () use ($companyId, $branch, $mode, $actor): ?string {
            $setting = BranchSetting::query()->firstOrNew([
                'branch_id' => $branch->id,
                'key' => BranchSetting::KEY_DINE_IN_ROUND_MODE,
            ], ['company_id' => $companyId]);

            $value = $setting->value;
            $old = is_string($value) && in_array($value, ['kitchen_direct', 'staff_confirm'], true)
                ? $value
                : null;

            if ((! $setting->exists && $mode === null) || ($mode !== null && $old === $mode)) {
                return $mode;
            }

            if ($mode === null) {
                // Even a malformed stored override is removed on inherit.
                $setting->delete();
            } else {
                $setting->value = $mode;
                $setting->save();
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.dine_in_round_mode.branch_updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: Branch::class,
                auditableId: $branch->id,
                oldValues: ['dine_in_round_mode' => $old],
                newValues: ['dine_in_round_mode' => $mode],
            ));

            return $mode;
        });
    }
}
