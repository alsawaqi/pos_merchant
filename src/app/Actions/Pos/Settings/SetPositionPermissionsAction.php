<?php

declare(strict_types=1);

namespace App\Actions\Pos\Settings;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CompanySetting;
use App\Models\User;
use App\Support\MerchantTenantContext;
use App\Support\PositionPermissions;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 B1 — save the staff permissions tick list.
 *
 * The submitted cells are laid over the company's current resolved matrix
 * (so a partial payload changes only what it names), always-on cells are
 * forced on, and the FULL resolved matrix is stored under
 * `position_permissions`. In the same transaction the four old position
 * lists are rewritten from it for old app builds
 * ({@see PositionPermissions::legacyLists()}); order_cancel_positions keeps
 * every position it already had (fix order 1, L4).
 *
 * Fix order 1, L5 — "current" for a company with no row yet is the no-row
 * rule (defaults + its old lists), so a first save keeps, e.g., supervisors
 * as approvers when the old list had them.
 *
 * One audit row per save that changes anything
 * (`settings.position_permissions.updated`): only the changed cells, as
 * "position.action" / "position.discount_max_percent" → old and new value.
 */
final readonly class SetPositionPermissionsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, array{actions?: array<string, bool>, discount_max_percent?: int|float}>  $changes
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int|float}>
     */
    public function handle(array $changes, User $actor): array
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($companyId, $changes, $actor): array {
            $setting = CompanySetting::query()->firstOrNew([
                'company_id' => $companyId,
                'key' => PositionPermissions::SETTING_KEY,
            ]);
            $old = PositionPermissions::forCompany($companyId);

            $next = PositionPermissions::overlay($old, $changes);

            $diffOld = [];
            $diffNew = [];
            foreach (PositionPermissions::POSITIONS as $position) {
                foreach (PositionPermissions::ACTIONS as $action) {
                    if ($old[$position]['actions'][$action] !== $next[$position]['actions'][$action]) {
                        $diffOld["{$position}.{$action}"] = $old[$position]['actions'][$action];
                        $diffNew["{$position}.{$action}"] = $next[$position]['actions'][$action];
                    }
                }
                if ($old[$position]['discount_max_percent'] != $next[$position]['discount_max_percent']) {
                    $diffOld["{$position}.discount_max_percent"] = $old[$position]['discount_max_percent'];
                    $diffNew["{$position}.discount_max_percent"] = $next[$position]['discount_max_percent'];
                }
            }

            // Write the row even when nothing changed but it did not exist yet,
            // so the company's matrix is explicit from its first save.
            if ($diffNew !== [] || ! $setting->exists) {
                $setting->value = $next;
                $setting->save();
            }

            foreach (PositionPermissions::legacyLists($next) as $key => $positions) {
                $legacy = CompanySetting::query()->firstOrNew(['company_id' => $companyId, 'key' => $key]);
                $current = is_array($legacy->value) ? array_values($legacy->value) : null;
                if ($key === CompanySetting::KEY_ORDER_CANCEL_POSITIONS) {
                    $positions = PositionPermissions::orderCancelList($next, $legacy->exists ? $legacy->value : null);
                }
                if ($current !== $positions) {
                    $legacy->value = $positions;
                    $legacy->save();
                }
            }

            if ($diffNew !== []) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'settings.position_permissions.updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: CompanySetting::class,
                    auditableId: $setting->id,
                    oldValues: $diffOld,
                    newValues: $diffNew,
                ));
            }

            return $next;
        });
    }
}
