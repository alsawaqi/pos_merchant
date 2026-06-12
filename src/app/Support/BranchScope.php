<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use App\Models\User;

/**
 * P-G5 — branch-scope enforcement helpers (blueprint §5.1.2: role =
 * WHAT you can do, branch scope = WHERE you can do it).
 *
 * The source of truth is {@see User::allowedBranchIds()} (NULL =
 * unrestricted; SuperAdmin is always unrestricted). These helpers are
 * the terse call-site verbs the controllers use on top of it. The
 * convention everywhere:
 *
 *   - an EXPLICIT request for an out-of-scope branch → 403 (the spec
 *     forbids silent hiding for direct requests);
 *   - a LIST with no branch input → silently filtered to the scope;
 *   - company-central data (branch_id NULL: warehouse pool, HQ
 *     expenses) and central-warehouse MUTATIONS → unrestricted users
 *     only.
 *
 * Cross-tenant ordering: tenant checks (404) must run BEFORE scope
 * checks (403) so a foreign uuid never reveals its existence — the
 * EnsureBranchScope middleware and every call site preserve that.
 */
final class BranchScope
{
    /** @return list<int>|null */
    public static function allowedIds(?User $user): ?array
    {
        return $user instanceof User ? $user->allowedBranchIds() : null;
    }

    /** Abort 403 unless the user may act on [$branch]. */
    public static function ensureBranch(?User $user, Branch|int|null $branchId): void
    {
        if (! $user instanceof User) {
            return;
        }
        $id = $branchId instanceof Branch ? (int) $branchId->id : $branchId;
        if (! $user->canAccessBranchId($id)) {
            abort(403, 'Your account is restricted to specific branches.');
        }
    }

    /**
     * Abort 403 unless the user is unrestricted — the gate for
     * central-warehouse mutations (receive / allocate / distribute /
     * central adjust) and other HQ-only writes.
     */
    public static function ensureUnrestricted(?User $user, string $message = 'Your account is restricted to specific branches.'): void
    {
        if ($user instanceof User && $user->allowedBranchIds() !== null) {
            abort(403, $message);
        }
    }

    /**
     * The effective branch-id list for a LIST query: the requested ids
     * when in scope (403 on any out-of-scope id — explicit requests
     * never silently shrink), the full scope when nothing was
     * requested, or NULL when both are unrestricted.
     *
     * @param  list<int>|null  $requested
     * @return list<int>|null
     */
    public static function constrain(?User $user, ?array $requested): ?array
    {
        $allowed = self::allowedIds($user);
        if ($allowed === null) {
            return $requested;
        }
        if ($requested === null || $requested === []) {
            return $allowed;
        }
        foreach ($requested as $id) {
            if (! in_array((int) $id, $allowed, true)) {
                abort(403, 'Your account is restricted to specific branches.');
            }
        }

        return array_values(array_map(static fn ($v): int => (int) $v, $requested));
    }
}
