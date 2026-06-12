<?php

declare(strict_types=1);

namespace App\Actions\Pos\Messaging;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\PosStaff;
use App\Models\StaffMessage;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use App\Support\ReverbPublisher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G6 — compose a staff announcement (portal → POS devices). Targets:
 *
 *   staff    one staff member (resolved by uuid; shows only when THEY
 *            are logged in on a till);
 *   branch   everyone on a till at that branch;
 *   company  every branch.
 *
 * F5 branch scope applies to the SENDER: a branch-restricted user may
 * announce only to their branches (their staff included); company-wide
 * needs an unrestricted account. After the commit a best-effort Reverb
 * nudge ('message.created') hits the audience's branch channels so idle
 * tills pull the config delta within seconds; offline devices catch up
 * on their next sync.
 */
final readonly class SendStaffMessageAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private ReverbPublisher $reverb,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): StaffMessage
    {
        $companyId = $this->tenant->requiredId();
        $targetType = (string) $attributes['target_type'];

        $targetBranchId = null;
        $targetStaffId = null;
        $nudgeBranchIds = [];

        if ($targetType === StaffMessage::TARGET_BRANCH) {
            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->where('uuid', (string) ($attributes['target_branch_uuid'] ?? ''))
                ->first();
            if ($branch === null) {
                throw new RuntimeException('The selected branch was not found.');
            }
            BranchScope::ensureBranch($actor, $branch);
            $targetBranchId = (int) $branch->id;
            $nudgeBranchIds = [$targetBranchId];
        } elseif ($targetType === StaffMessage::TARGET_STAFF) {
            $staff = PosStaff::query()
                ->where('company_id', $companyId)
                ->where('uuid', (string) ($attributes['target_staff_uuid'] ?? ''))
                ->first();
            if ($staff === null) {
                throw new RuntimeException('The selected staff member was not found.');
            }
            BranchScope::ensureBranch($actor, (int) $staff->branch_id);
            $targetStaffId = (int) $staff->id;
            $nudgeBranchIds = [(int) $staff->branch_id];
        } elseif ($targetType === StaffMessage::TARGET_COMPANY) {
            BranchScope::ensureUnrestricted($actor, 'Announcing to all branches needs an account with access to all branches.');
            $nudgeBranchIds = Branch::query()
                ->where('company_id', $companyId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        } else {
            throw new RuntimeException('Unknown announcement target.');
        }

        $message = DB::transaction(function () use ($attributes, $actor, $companyId, $targetType, $targetBranchId, $targetStaffId): StaffMessage {
            $message = StaffMessage::query()->create([
                'company_id' => $companyId,
                'target_type' => $targetType,
                'target_branch_id' => $targetBranchId,
                'target_staff_id' => $targetStaffId,
                'title' => $attributes['title'] ?? null,
                'body' => (string) $attributes['body'],
                'created_by_user_id' => $actor->getKey(),
                // Devices render the sender without a users join.
                'created_by_name' => $actor->name,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'messaging.staff_message.sent',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $targetBranchId,
                auditableType: StaffMessage::class,
                auditableId: $message->id,
                newValues: [
                    'target_type' => $targetType,
                    'target_staff_id' => $targetStaffId,
                    'title' => $message->title,
                ],
            ));

            return $message;
        });

        // Live nudge AFTER the commit — advisory only.
        $this->reverb->publishToBranches($nudgeBranchIds, 'message.created', [
            'type' => 'message.created',
            'message_id' => (int) $message->id,
        ]);

        return $message;
    }
}
