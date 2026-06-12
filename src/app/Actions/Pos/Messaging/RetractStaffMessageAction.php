<?php

declare(strict_types=1);

namespace App\Actions\Pos\Messaging;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\PosStaff;
use App\Models\StaffMessage;
use App\Models\User;
use App\Support\MerchantTenantContext;
use App\Support\ReverbPublisher;
use Illuminate\Support\Facades\DB;

/**
 * P-G6 — retract a staff announcement. Soft delete: pos_api's config
 * delta lists the id under deleted.staff_messages, so devices purge it
 * from their cache; the nudge makes that prompt on idle tills.
 */
final readonly class RetractStaffMessageAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private ReverbPublisher $reverb,
    ) {}

    public function handle(StaffMessage $message, User $actor): void
    {
        $companyId = $this->tenant->requiredId();

        DB::transaction(function () use ($message, $actor, $companyId): void {
            $message->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'messaging.staff_message.retracted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: StaffMessage::class,
                auditableId: $message->id,
                oldValues: [
                    'target_type' => $message->target_type,
                    'title' => $message->title,
                ],
            ));
        });

        $branchIds = match ($message->target_type) {
            StaffMessage::TARGET_BRANCH => [(int) $message->target_branch_id],
            StaffMessage::TARGET_STAFF => array_filter([
                (int) (PosStaff::query()->whereKey($message->target_staff_id)->value('branch_id') ?? 0),
            ]),
            default => Branch::query()->where('company_id', $companyId)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        };

        $this->reverb->publishToBranches(array_values($branchIds), 'message.deleted', [
            'type' => 'message.deleted',
            'message_id' => (int) $message->id,
        ]);
    }
}
