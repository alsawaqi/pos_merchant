<?php

declare(strict_types=1);

namespace App\Actions\Pos\Branch;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\BranchOrderType;
use App\Enums\BranchStatus;
use App\Enums\MerchantPermission;
use App\Models\Branch;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Merchant-side partial update of one of the company's own
 * branches.
 *
 * Whitelist of mutable fields (anything else in $attributes is
 * silently dropped to defend against extra keys leaking through
 * a future controller bug):
 *
 *   - name, name_ar         — display labels
 *   - manager_name          — operational contact
 *   - phone, email, address — branch reachability
 *   - opening_hours_json    — weekly schedule
 *   - default_order_type    — Main POS UX default
 *   - status                — gated EXTRA on
 *                              BranchesTransitionStatus permission
 *                              because deactivating a branch
 *                              stops POS orders + bills
 *
 * Location (latitude/longitude/geofence_radius_m) is admin-owned and is
 * deliberately NOT in this whitelist — it is set + kept in pos_admin.
 *
 * Cross-tenant refusal at the action layer is defence in depth on
 * top of the controller's refuseIfNotInTenant() guard — a future
 * job/CLI caller still can't escape the tenancy.
 *
 * Audit event: `branch.updated` with old + new diffs for every
 * field that actually changed (no-op saves do NOT write an audit
 * row to avoid noise).
 */
final readonly class UpdateMerchantBranchAction
{
    /**
     * The exact set of fields a merchant is allowed to PATCH.
     * Mirrored in Branch::$fillable for mass-assignment safety,
     * duplicated here so the Action remains self-contained even
     * if a future model edit relaxes the model whitelist.
     *
     * @var list<string>
     */
    private const MUTABLE_FIELDS = [
        'name',
        'name_ar',
        'manager_name',
        'phone',
        'email',
        'address',
        'opening_hours_json',
        'default_order_type',
        // status is in the list but gated by the extra
        // BranchesTransitionStatus permission check below.
        'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Branch $branch, array $attributes, User $actor): Branch
    {
        $companyId = $this->tenant->requiredId();

        // Cross-tenant guard — controller should have refused
        // already, this is belt-and-braces.
        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($branch, $attributes, $actor, $companyId): Branch {
            $changes = [];

            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $newValue = $attributes[$field];

                // Special-case status — requires the separate
                // BranchesTransitionStatus permission. Quietly
                // skipping (vs throwing) would mask a UI bug
                // where the form submitted a status the user
                // can't actually change; throw 403-equivalent.
                if ($field === 'status') {
                    $current = $branch->status?->value;
                    if ((string) $newValue === $current) {
                        // No-op — the form just round-tripped
                        // the same value. Don't tax the gate.
                        continue;
                    }
                    if (! $actor->can(MerchantPermission::BranchesTransitionStatus->value)) {
                        throw new RuntimeException(
                            'You do not have permission to change a branch status.',
                        );
                    }
                }

                $oldValue = $branch->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum
                    ? $oldValue->value
                    : $oldValue;

                // Comparing arrays via != would treat
                // [1,2] and [2,1] as different even though both
                // are valid opening_hours_json shapes; we compare
                // by json string for that case.
                $sameValue = is_array($oldComparable) || is_array($newValue)
                    ? json_encode($oldComparable) === json_encode($newValue)
                    : $oldComparable == $newValue; // intentionally loose: "1" vs 1

                if ($sameValue) {
                    continue;
                }

                $changes[$field] = [
                    'old' => $oldComparable,
                    'new' => $newValue instanceof \BackedEnum ? $newValue->value : $newValue,
                ];
                $branch->{$field} = $newValue;
            }

            if ($changes === []) {
                // Nothing to write + nothing to audit — return
                // a freshly-fetched copy for response shaping.
                return $branch->fresh();
            }

            $branch->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'branch.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: Branch::class,
                auditableId: $branch->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $branch->fresh();
        });
    }
}
