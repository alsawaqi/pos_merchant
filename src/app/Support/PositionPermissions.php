<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 D1 — the tick list per staff position ("what each position may
 * do on the till and handheld without a manager's approval").
 *
 * Stored as ONE pos_company_settings row, key `position_permissions`:
 *
 *   { "<position>": { "actions": { "<key>": true|false, ... },
 *                     "discount_max_percent": 0..100 }, ... }
 *
 * The defaults below are the shared fixture
 * D:\launch-work\p5\shared\position_permissions_defaults.json (a test keeps
 * them identical, key for key and value for value). pos_api has its own
 * resolver with the same rule: anything missing or malformed resolves to
 * the default, so a company with no row behaves exactly like the defaults.
 *
 * The four pre-P5 position lists are derived from the matrix and kept in
 * sync on every save, so old app builds keep working:
 *   manager_approval_positions ← approvals.give
 *   reports_positions          ← reports.view
 *   kitchen_positions          ← kitchen.screen (the kitchen role is always
 *                                 implicit there, as the old page stored it)
 *   order_cancel_positions     ← the stored list ∪ order.void_paid
 *                                 (fix order 1, L4: never narrowed)
 *
 * Fix order 1, L5 — the same rules as pos_api's resolver
 * (App\Support\Staff\PositionPermissions at api 8d2e6e5):
 *   - a company with NO `position_permissions` row resolves like the P5 data
 *     migration would write it: the defaults plus the three old lists
 *     ({@see fromOldLists()}; order_cancel_positions is never mapped);
 *   - `discount_max_percent` is floored to a whole number.
 * tests/Fixtures/launch-p5/resolver_corner_cases.json holds inputs and the
 * matrices pos_api's resolver produced for them.
 */
final class PositionPermissions
{
    public const SETTING_KEY = 'position_permissions';

    /** @var list<string> */
    public const POSITIONS = ['cashier', 'waiter', 'kitchen', 'supervisor', 'manager'];

    /** @var list<string> */
    public const ACTIONS = [
        'order.void_unpaid', 'order.void_paid', 'table.cancel_line', 'table.cancel_bill',
        'discount.manual', 'comp', 'gift', 'loyalty.redeem', 'sold_out.toggle',
        'receipt.reprint', 'kitchen.reprint', 'reports.view', 'kitchen.screen',
        'shift.close_other', 'payout', 'stock.waste', 'stock.count', 'training.use', 'approvals.give',
    ];

    /**
     * Cells that are always ticked whatever is stored: the kitchen role
     * always opens the kitchen screen (today's kitchen_positions rule).
     *
     * @var array<string, list<string>>
     */
    public const ALWAYS_ON = ['kitchen' => ['kitchen.screen']];

    /**
     * @var array<string, array{discount_max_percent: int, actions: array<string, bool>}>
     */
    public const DEFAULTS = [
        'cashier' => [
            'discount_max_percent' => 10,
            'actions' => [
                'order.void_unpaid' => false, 'order.void_paid' => false, 'table.cancel_line' => false, 'table.cancel_bill' => false,
                'discount.manual' => true, 'comp' => false, 'gift' => false, 'loyalty.redeem' => false, 'sold_out.toggle' => false,
                'receipt.reprint' => true, 'kitchen.reprint' => false, 'reports.view' => false, 'kitchen.screen' => false,
                'shift.close_other' => false, 'payout' => false, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => false,
            ],
        ],
        'waiter' => [
            'discount_max_percent' => 10,
            'actions' => [
                'order.void_unpaid' => false, 'order.void_paid' => false, 'table.cancel_line' => false, 'table.cancel_bill' => false,
                'discount.manual' => true, 'comp' => false, 'gift' => false, 'loyalty.redeem' => false, 'sold_out.toggle' => false,
                'receipt.reprint' => true, 'kitchen.reprint' => false, 'reports.view' => false, 'kitchen.screen' => false,
                'shift.close_other' => false, 'payout' => false, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => false,
            ],
        ],
        'kitchen' => [
            'discount_max_percent' => 0,
            'actions' => [
                'order.void_unpaid' => false, 'order.void_paid' => false, 'table.cancel_line' => false, 'table.cancel_bill' => false,
                'discount.manual' => false, 'comp' => false, 'gift' => false, 'loyalty.redeem' => false, 'sold_out.toggle' => false,
                'receipt.reprint' => true, 'kitchen.reprint' => false, 'reports.view' => false, 'kitchen.screen' => true,
                'shift.close_other' => false, 'payout' => false, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => false,
            ],
        ],
        'supervisor' => [
            'discount_max_percent' => 25,
            'actions' => [
                'order.void_unpaid' => true, 'order.void_paid' => false, 'table.cancel_line' => true, 'table.cancel_bill' => false,
                'discount.manual' => true, 'comp' => false, 'gift' => false, 'loyalty.redeem' => true, 'sold_out.toggle' => true,
                'receipt.reprint' => true, 'kitchen.reprint' => true, 'reports.view' => false, 'kitchen.screen' => false,
                'shift.close_other' => true, 'payout' => true, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => false,
            ],
        ],
        'manager' => [
            'discount_max_percent' => 100,
            'actions' => [
                'order.void_unpaid' => true, 'order.void_paid' => true, 'table.cancel_line' => true, 'table.cancel_bill' => true,
                'discount.manual' => true, 'comp' => true, 'gift' => true, 'loyalty.redeem' => true, 'sold_out.toggle' => true,
                'receipt.reprint' => true, 'kitchen.reprint' => true, 'reports.view' => true, 'kitchen.screen' => true,
                'shift.close_other' => true, 'payout' => true, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => true,
            ],
        ],
    ];

    /**
     * The three old lists the no-row rule reads (pos_api's OLD_KEYS):
     * order_cancel_positions is not mapped.
     */
    public const NO_ROW_LISTS = [
        CompanySetting::KEY_MANAGER_APPROVAL_POSITIONS => 'approvals.give',
        CompanySetting::KEY_REPORTS_POSITIONS => 'reports.view',
        CompanySetting::KEY_KITCHEN_POSITIONS => 'kitchen.screen',
    ];

    /** Old list key → the action it mirrors. */
    public const LEGACY_LISTS = [
        CompanySetting::KEY_ORDER_CANCEL_POSITIONS => 'order.void_paid',
        CompanySetting::KEY_MANAGER_APPROVAL_POSITIONS => 'approvals.give',
        CompanySetting::KEY_REPORTS_POSITIONS => 'reports.view',
        CompanySetting::KEY_KITCHEN_POSITIONS => 'kitchen.screen',
    ];

    /**
     * The full, resolved matrix: every position and every action, in the
     * fixed order. A stored value counts only when it has the right type
     * (a boolean tick; a number 0..100 for the limit); anything missing or
     * malformed falls back to the default. Always-on cells are forced on.
     *
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>
     */
    public static function resolve(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (! is_array($stored)) {
            $stored = [];
        }

        $out = [];
        foreach (self::POSITIONS as $position) {
            $default = self::DEFAULTS[$position];
            $row = is_array($stored[$position] ?? null) ? $stored[$position] : [];
            $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];

            $resolved = [];
            foreach (self::ACTIONS as $action) {
                $value = $actions[$action] ?? null;
                $resolved[$action] = is_bool($value) ? $value : $default['actions'][$action];
            }
            foreach (self::ALWAYS_ON[$position] ?? [] as $action) {
                $resolved[$action] = true;
            }

            $limit = $row['discount_max_percent'] ?? null;
            $validLimit = (is_int($limit) || is_float($limit)) && $limit >= 0 && $limit <= 100;

            $out[$position] = [
                'actions' => $resolved,
                // L5 — floored to a whole number, as pos_api does.
                'discount_max_percent' => $validLimit ? (int) floor($limit) : $default['discount_max_percent'],
            ];
        }

        return $out;
    }

    /**
     * Lay submitted cells over a resolved matrix and resolve the result.
     *
     * @param  array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>  $base
     * @param  array<string, array{actions?: array<string, bool>, discount_max_percent?: int|float}>  $changes
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>
     */
    public static function overlay(array $base, array $changes): array
    {
        foreach ($changes as $position => $row) {
            if (! isset($base[$position]) || ! is_array($row)) {
                continue;
            }
            foreach (is_array($row['actions'] ?? null) ? $row['actions'] : [] as $action => $allowed) {
                if (array_key_exists($action, $base[$position]['actions'])) {
                    $base[$position]['actions'][$action] = (bool) $allowed;
                }
            }
            if (array_key_exists('discount_max_percent', $row)) {
                $base[$position]['discount_max_percent'] = $row['discount_max_percent'];
            }
        }

        return self::resolve($base);
    }

    /**
     * The resolved matrix of a company (defaults when it has no row).
     *
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>
     */
    public static function forCompany(int $companyId): array
    {
        $rows = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->whereIn('key', [self::SETTING_KEY, ...array_keys(self::NO_ROW_LISTS)])
            ->pluck('value', 'key')
            ->all();

        if (array_key_exists(self::SETTING_KEY, $rows)) {
            return self::resolve($rows[self::SETTING_KEY]);
        }

        return self::fromOldLists(array_intersect_key($rows, self::NO_ROW_LISTS));
    }

    /**
     * L5 — the matrix of a company with no `position_permissions` row, as the
     * P5 data migration (and pos_api's fromOldLists) writes it: a listed
     * position gets the action and an unlisted one loses it; a missing,
     * malformed or known-position-free list keeps the default; a present but
     * empty kitchen list means "kitchen only".
     *
     * @param  array<string, mixed>  $lists  old key => raw value
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int}>
     */
    public static function fromOldLists(array $lists): array
    {
        $matrix = self::defaults();
        foreach (self::NO_ROW_LISTS as $key => $action) {
            if (! array_key_exists($key, $lists)) {
                continue;
            }
            $raw = $lists[$key];
            $value = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($value)) {
                continue;
            }
            $listed = array_values(array_intersect(self::POSITIONS, array_map(
                static fn ($p): string => is_string($p) ? trim($p) : '',
                $value,
            )));
            if ($listed === [] && $action !== 'kitchen.screen') {
                continue;
            }
            foreach (self::POSITIONS as $position) {
                $matrix[$position]['actions'][$action] = in_array($position, $listed, true)
                    || ($action === 'kitchen.screen' && $position === 'kitchen');
            }
        }

        return $matrix;
    }

    /**
     * L4 — order_cancel_positions for old builds: the stored list (its known
     * positions) ∪ the positions holding order.void_paid, in the fixed
     * order. An unrelated save never narrows it; it stays a superset until
     * old builds are retired (on old builds it only opens the cancel screen,
     * which still asks for a manager).
     *
     * @param  array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>  $matrix
     * @return list<string>
     */
    public static function orderCancelList(array $matrix, mixed $stored): array
    {
        $value = is_string($stored) ? json_decode($stored, true) : $stored;
        $kept = is_array($value)
            ? array_map(static fn ($p): string => is_string($p) ? trim($p) : '', $value)
            : [];

        return array_values(array_filter(
            self::POSITIONS,
            static fn (string $p): bool => in_array($p, $kept, true) || ($matrix[$p]['actions']['order.void_paid'] ?? false) === true,
        ));
    }

    /**
     * The positions of a company whose tick list holds approvals.give (the
     * people who may approve other people's actions), in the fixed order.
     *
     * @return list<string>
     */
    public static function approverPositions(int $companyId): array
    {
        $matrix = self::forCompany($companyId);

        return array_values(array_filter(
            self::POSITIONS,
            static fn (string $p): bool => $matrix[$p]['actions']['approvals.give'],
        ));
    }

    /**
     * The defaults in the resolved shape (what the page offers as "Restore
     * defaults").
     *
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>
     */
    public static function defaults(): array
    {
        return self::resolve([]);
    }

    /**
     * The four old position lists, derived from a resolved matrix. The
     * kitchen role is left out of kitchen_positions (it is always implicit
     * there; the old page never stored it).
     *
     * @param  array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>  $matrix
     * @return array<string, list<string>>
     */
    public static function legacyLists(array $matrix): array
    {
        $lists = [];
        foreach (self::LEGACY_LISTS as $key => $action) {
            $positions = [];
            foreach (self::POSITIONS as $position) {
                if ($key === CompanySetting::KEY_KITCHEN_POSITIONS && $position === 'kitchen') {
                    continue;
                }
                if ($matrix[$position]['actions'][$action] ?? false) {
                    $positions[] = $position;
                }
            }
            $lists[$key] = $positions;
        }

        return $lists;
    }
}
