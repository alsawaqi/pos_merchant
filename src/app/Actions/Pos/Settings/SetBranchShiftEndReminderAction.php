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
 * LAUNCH-P5 B6 — the shift-end reminder time of a branch
 * (pos_branch_settings key `shift_end_reminder_at`): "HH:MM" in Muscat time,
 * or null = off. pos_api sends it to the devices
 * (settings.shift_end_reminder_at); at that time, while a person's shift is
 * open, the till and handheld show a banner and sound, every 15 minutes until
 * the shift is closed. Audited (`settings.shift_end_reminder.branch_updated`).
 */
final readonly class SetBranchShiftEndReminderAction
{
    public const PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public static function current(Branch $branch): ?string
    {
        $value = BranchSetting::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->where('key', BranchSetting::KEY_SHIFT_END_REMINDER_AT)
            ->value('value');

        return is_string($value) && preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    public function handle(Branch $branch, ?string $time, User $actor): ?string
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }
        if ($time !== null && preg_match(self::PATTERN, $time) !== 1) {
            throw new InvalidArgumentException('The reminder time must be HH:MM (00:00 to 23:59).');
        }

        return DB::transaction(function () use ($branch, $time, $actor, $companyId): ?string {
            $old = self::current($branch);
            $setting = BranchSetting::query()->firstOrNew(
                ['branch_id' => $branch->id, 'key' => BranchSetting::KEY_SHIFT_END_REMINDER_AT],
                ['company_id' => $companyId],
            );
            if ($setting->exists && $old === $time) {
                return $time;
            }
            $setting->value = $time;
            $setting->save();

            if ($old !== $time) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.shift_end_reminder.branch_updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    branchId: $branch->id,
                    auditableType: Branch::class,
                    auditableId: $branch->id,
                    oldValues: ['shift_end_reminder_at' => $old],
                    newValues: ['shift_end_reminder_at' => $time],
                ));
            }

            return $time;
        });
    }
}
