<?php

declare(strict_types=1);

namespace App\Actions\Pos\Targets;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\BranchTarget;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * P-G8 — soft-delete a branch target. Its finalized window history
 * survives (the rows reference the target id; reports on past
 * performance must not vanish with the config).
 */
final readonly class DeleteBranchTargetAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(BranchTarget $target, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $target->company_id !== $companyId) {
            abort(404);
        }
        BranchScope::ensureBranch($actor, (int) $target->branch_id);

        DB::transaction(function () use ($target, $actor, $companyId): void {
            $targetId = $target->id;
            $snapshot = [
                'period' => $target->period->value,
                'amount' => (string) $target->amount,
                'window_periods' => (int) $target->window_periods,
                'starts_on' => $target->starts_on->toDateString(),
                'is_active' => (bool) $target->is_active,
            ];

            $target->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.branch_target.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: BranchTarget::class,
                auditableId: $targetId,
                branchId: (int) $target->branch_id,
                oldValues: $snapshot,
            ));
        });
    }
}
