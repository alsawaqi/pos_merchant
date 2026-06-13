<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOn;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        // P-G3 — product-as-add-on (resolved from linked_product_uuid).
        'linked_product_id',
        'display_order',
        'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private SyncAddOnConsumptionAction $syncConsumption,
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

        // P-G3 — translate the wire uuid into the stored id (null clears
        // the link, back to a classic label-only option).
        if (array_key_exists('linked_product_uuid', $attributes)) {
            $uuid = $attributes['linked_product_uuid'];
            if ($uuid === null || $uuid === '') {
                $attributes['linked_product_id'] = null;
            } else {
                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->where('uuid', (string) $uuid)
                    ->first();
                if ($product === null) {
                    throw new RuntimeException('The linked product does not belong to your company.');
                }
                if ($product->is_internal) {
                    throw new RuntimeException('An internal item cannot be sold as an add-on.');
                }
                $attributes['linked_product_id'] = (int) $product->id;
            }
        }

        // PD3b — the stock-usage lines sync independently of the scalar
        // fields: a key present in the payload (even []) replaces the
        // set; an absent key leaves the lines untouched.
        $syncLines = array_key_exists('consumption', $attributes);
        $consumption = $syncLines && is_array($attributes['consumption']) ? $attributes['consumption'] : [];

        // Re-linking onto a product an EXISTING usage line already consumes
        // (and this payload isn't replacing the lines) would double-consume
        // at sale — the mirror of the sync action's own collision guard.
        if (! $syncLines
            && isset($attributes['linked_product_id'])
            && $addon->consumptionLines()->where('component_product_id', (int) $attributes['linked_product_id'])->exists()) {
            throw new RuntimeException('This option already has a stock-usage line for that product — selling it would consume the product twice. Remove the line first.');
        }

        // ONE outer transaction for scalars + lines: a rejected usage line
        // must roll back the scalar changes too (the create path's
        // contract — "a bad line rolls back the whole option").
        return DB::transaction(function () use ($addon, $attributes, $actor, $companyId, $syncLines, $consumption): AddOn {
            $updated = $this->applyScalarFields($addon, $attributes, $actor, $companyId);

            if ($syncLines) {
                $updated = $this->syncConsumption->handle($updated, $consumption, $actor);
            }

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyScalarFields(AddOn $addon, array $attributes, User $actor, int $companyId): AddOn
    {
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
