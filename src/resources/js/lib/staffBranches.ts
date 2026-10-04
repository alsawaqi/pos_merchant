/**
 * LAUNCH-P5 B2 — pure helpers for the staff form's branch multi-select (no
 * Vue, so the node tests can load them). The server enforces the rules: the
 * home branch is always included, and a branch-limited user may change only
 * the branches inside their scope (the others are kept as they are).
 */

export interface StaffBranchRef {
    id: number;
    name?: string | null;
    home?: boolean;
}

/** The `branch_ids` payload: the home branch first, then the ticked others, no duplicates. */
export function branchIdsPayload(homeId: number, ticked: number[]): number[] {
    const others = ticked.filter((id) => id !== homeId);
    return [homeId, ...Array.from(new Set(others)).sort((a, b) => a - b)];
}

/** The person's branches this user cannot see or change (shown locked). */
export function lockedBranches(staffBranches: StaffBranchRef[] | undefined, visibleIds: number[]): StaffBranchRef[] {
    return (staffBranches ?? []).filter((b) => !visibleIds.includes(b.id));
}

/** The person's other (non-home) branches the user can tick. */
export function tickedOthers(staffBranches: StaffBranchRef[] | undefined, homeId: number, visibleIds: number[]): number[] {
    return (staffBranches ?? []).map((b) => b.id).filter((id) => id !== homeId && visibleIds.includes(id));
}
