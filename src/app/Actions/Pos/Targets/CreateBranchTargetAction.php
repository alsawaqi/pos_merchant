<?php

declare(strict_types=1);

namespace App\Actions\Pos\Targets;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\BranchTargetPeriod;
use App\Models\Branch;
use App\Models\BranchTarget;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G8 — create a branch sales target. ONE active target per branch:
 * creating a new one DEACTIVATES the branch's current active target
 * (its finalized window history stays). Structural fields (period /
 * window size / anchor) are immutable after creation, so "change the
 * schedule" = create a replacement — which is exactly this action.
 *
 * F5: the target's branch must be inside the actor's scope.
 */
final readonly class CreateBranchTargetAction
{
    public function __construct(
        private EvaluateBranchTargetsAction $evaluate,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{branch_uuid: string, period: string, amount: numeric, window_periods: int, starts_on: string}  $attributes
     */
    public function handle(array $attributes, User $actor): BranchTarget
    {
        $companyId = $this->tenant->requiredId();

        $branch = Branch::query()
            ->where('company_id', $companyId)
            ->where('uuid', (string) $attributes['branch_uuid'])
            ->first();
        if ($branch === null) {
            throw new RuntimeException('Branch not found.');
        }
        BranchScope::ensureBranch($actor, (int) $branch->id);

        $period = BranchTargetPeriod::from((string) $attributes['period']);

        // SEAL the outgoing target's elapsed windows before retiring it —
        // a deactivated target stops being evaluated, so anything still
        // unfinalized would otherwise freeze unfinalized forever.
        $outgoing = BranchTarget::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->get();
        foreach ($outgoing as $old) {
            $this->evaluate->finalizeDueWindows($old);
        }

        return DB::transaction(function () use ($attributes, $actor, $companyId, $branch, $period): BranchTarget {
            // Retire the branch's current active target (history kept).
            $previous = BranchTarget::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->get();
            foreach ($previous as $old) {
                $old->forceFill(['is_active' => false])->save();
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.branch_target.replaced',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: BranchTarget::class,
                    auditableId: $old->id,
                    branchId: (int) $branch->id,
                    oldValues: ['is_active' => true],
                    newValues: ['is_active' => false],
                ));
            }

            $target = BranchTarget::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'period' => $period->value,
                'amount' => number_format((float) $attributes['amount'], 3, '.', ''),
                'window_periods' => (int) $attributes['window_periods'],
                'starts_on' => (string) $attributes['starts_on'],
                'is_active' => true,
                'created_by_user_id' => $actor->getKey(),
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.branch_target.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: BranchTarget::class,
                auditableId: $target->id,
                branchId: (int) $branch->id,
                newValues: [
                    'period' => $period->value,
                    'amount' => (string) $target->amount,
                    'window_periods' => (int) $target->window_periods,
                    'starts_on' => $target->starts_on->toDateString(),
                ],
            ));

            return $target->fresh();
        });
    }
}
