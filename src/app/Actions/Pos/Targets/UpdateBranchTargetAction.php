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
 * P-G8 — partial-update a branch target. Only [amount] and [is_active]
 * are mutable: an amount edit applies to the CURRENT + future windows
 * (finalized history froze its goal per window); structural changes
 * (period / window size / anchor) are a replace via Create. Diff-aware
 * audit, the UpdateDeliveryProviderAction pattern.
 */
final readonly class UpdateBranchTargetAction
{
    public function __construct(
        private EvaluateBranchTargetsAction $evaluate,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(BranchTarget $target, array $attributes, User $actor): BranchTarget
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $target->company_id !== $companyId) {
            abort(404);
        }
        BranchScope::ensureBranch($actor, (int) $target->branch_id);

        // SEAL history under the in-force goal BEFORE any amount change:
        // finalization is lazy, so elapsed-but-unfinalized windows would
        // otherwise freeze later against a goal that was never in force
        // during those days (false misses + a wrongful "just missed" popup).
        $this->evaluate->finalizeDueWindows($target);

        // Re-activation retires the branch's sibling active targets below —
        // seal THEIR elapsed windows first (outside the transaction: the
        // finalizer's unique-violation race handling must never poison it).
        if ((bool) ($attributes['is_active'] ?? false) && ! $target->is_active) {
            $siblingsToSeal = BranchTarget::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $target->branch_id)
                ->where('is_active', true)
                ->where('id', '!=', $target->id)
                ->get();
            foreach ($siblingsToSeal as $sibling) {
                $this->evaluate->finalizeDueWindows($sibling);
            }
        }

        return DB::transaction(function () use ($target, $attributes, $actor, $companyId): BranchTarget {
            $changes = [];

            if (array_key_exists('amount', $attributes)) {
                $newAmount = number_format((float) $attributes['amount'], 3, '.', '');
                if ($newAmount !== (string) $target->amount) {
                    $changes['amount'] = ['old' => (string) $target->amount, 'new' => $newAmount];
                    $target->amount = $newAmount;
                }
            }
            if (array_key_exists('is_active', $attributes)) {
                $newActive = (bool) $attributes['is_active'];
                if ($newActive !== (bool) $target->is_active) {
                    // Re-activating must keep the one-active-per-branch
                    // invariant (otherwise two targets score the same sales
                    // and the dashboard doubles up) — retire any sibling.
                    if ($newActive) {
                        $siblings = BranchTarget::query()
                            ->where('company_id', $companyId)
                            ->where('branch_id', $target->branch_id)
                            ->where('is_active', true)
                            ->where('id', '!=', $target->id)
                            ->get();
                        foreach ($siblings as $sibling) {
                            $sibling->forceFill(['is_active' => false])->save();
                            $this->writeAuditLog->handle(new AuditLogData(
                                event: 'settings.branch_target.replaced',
                                actorUserId: $actor->getKey(),
                                companyId: $companyId,
                                auditableType: BranchTarget::class,
                                auditableId: $sibling->id,
                                branchId: (int) $target->branch_id,
                                oldValues: ['is_active' => true],
                                newValues: ['is_active' => false],
                            ));
                        }
                    }
                    $changes['is_active'] = ['old' => (bool) $target->is_active, 'new' => $newActive];
                    $target->is_active = $newActive;
                }
            }

            if ($changes === []) {
                return $target->fresh();
            }

            $target->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.branch_target.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: BranchTarget::class,
                auditableId: $target->id,
                branchId: (int) $target->branch_id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $target->fresh();
        });
    }
}
