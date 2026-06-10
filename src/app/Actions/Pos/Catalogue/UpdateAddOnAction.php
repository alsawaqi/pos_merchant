<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOn;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9 — partial-update an add-on. Mutable: name,
 * name_ar, price_delta, display_order, status. Moving an
 * add-on between groups is intentionally NOT supported (rare,
 * and changes the semantic meaning of the option — delete + re-
 * create is cleaner).
 *
 * Audit event: catalogue.addon.updated with old/new diffs.
 * Price changes are particularly important to capture for
 * historical pricing investigations.
 */
final readonly class UpdateAddOnAction
{
    private const MUTABLE_FIELDS = [
        'name',
        'name_ar',
        'price_delta',
        // Phase B — pre-selected default in the customize sheet.
        'is_default',
        'display_order',
        'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(AddOn $addon, array $attributes, User $actor): AddOn
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $addon->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($addon, $attributes, $actor, $companyId): AddOn {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $addon->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum ? $oldValue->value : $oldValue;

                // price_delta is decimal-cast → string. Normalize
                // for diff so '0.500' vs 0.5 doesn't trip.
                if ($field === 'price_delta') {
                    $sameValue = (string) $oldComparable === (string) $newValue;
                } else {
                    $sameValue = $oldComparable == $newValue;
                }
                if ($sameValue) {
                    continue;
                }

                $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                $addon->{$field} = $newValue;
            }

            if ($changes === []) {
                return $addon->fresh();
            }

            $addon->save();

            // A single-select group can only have ONE default — making
            // this option the default clears any sibling's flag.
            if (isset($changes['is_default']) && $addon->is_default) {
                $addon->loadMissing('group');
                if ($addon->group?->selection_mode?->value === 'single') {
                    AddOn::query()
                        ->where('add_on_group_id', $addon->add_on_group_id)
                        ->where('id', '!=', $addon->id)
                        ->update(['is_default' => false]);
                }
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.addon.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: AddOn::class,
                auditableId: $addon->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $addon->fresh();
        });
    }
}
