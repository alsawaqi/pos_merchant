<?php

declare(strict_types=1);

namespace App\Actions\Pos\Staff;

use App\Models\Branch;
use App\Models\PosStaff;
use App\Models\User;
use RuntimeException;

/**
 * LAUNCH-P5 B2 — the branches a staff member works at (one PIN at several
 * branches), stored in pos_staff_branches. The home branch
 * (pos_staff.branch_id) is always in the list.
 *
 * Branch scope: a user limited to some branches may add or remove only
 * branches inside their scope (an explicit out-of-scope id is a 403, never
 * silently dropped); the person's branches outside that scope are kept
 * untouched, because that user cannot see them.
 *
 * $requested = null means the form did not send a list (an old client):
 * hire → home only; edit → the current list, with a home move replacing the
 * old home.
 */
final readonly class SyncStaffBranchesAction
{
    /**
     * @param  list<int>|null  $requested
     * @return array{old: list<int>, new: list<int>} home first, then by id
     */
    public function handle(PosStaff $staff, ?array $requested, User $actor, ?int $previousHomeId = null): array
    {
        $companyId = (int) $staff->company_id;
        $homeId = (int) $staff->branch_id;

        $current = array_map('intval', $staff->branches()->pluck('pos_branches.id')->all());
        $old = $this->ordered($previousHomeId ?? $homeId, $current);

        if ($requested === null) {
            $next = $current;
            if ($previousHomeId !== null && $previousHomeId !== $homeId) {
                $next = array_values(array_diff($next, [$previousHomeId]));
            }
        } else {
            $requested = array_values(array_unique(array_map('intval', $requested)));
            $owned = Branch::query()->where('company_id', $companyId)->whereIn('id', $requested)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($owned) !== count($requested)) {
                throw new RuntimeException('A selected branch does not belong to your company.');
            }

            $allowed = $actor->allowedBranchIds();
            if ($allowed !== null) {
                foreach ($requested as $id) {
                    if (! in_array($id, $allowed, true)) {
                        abort(403, 'Your account is restricted to specific branches.');
                    }
                }
                $keptOutsideScope = array_values(array_diff($current, $allowed));
                $next = [...$requested, ...$keptOutsideScope];
            } else {
                $next = $requested;
            }
        }

        $next = $this->ordered($homeId, $next);

        $staff->branches()->sync(array_fill_keys($next, ['company_id' => $companyId]));

        return ['old' => $old, 'new' => $next];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ordered(int $homeId, array $ids): array
    {
        $others = array_values(array_diff(array_map('intval', $ids), [$homeId]));
        sort($others);

        return [$homeId, ...array_values(array_unique($others))];
    }
}
