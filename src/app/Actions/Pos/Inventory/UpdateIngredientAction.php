<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\Containers;
use App\Support\Inventory\CountContainerMirror;
use App\Support\Inventory\IngredientUnitLock;
use App\Support\Inventory\ItemCodes;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\PrepGraph;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5a — partial-update an ingredient.
 *
 * Diff-aware audit (inventory.ingredient.updated). One
 * critical restriction: the `unit` column CANNOT change once
 * any movement, stock, or recipe/add-on line exists for this
 * ingredient, because every historical movement AND every
 * recipe/consumption quantity is denominated in the original
 * base unit — "1.000" of kg means something completely
 * different than "1.000" of g. Forcing the merchant to remove
 * those references first (or create a new ingredient with the
 * new unit) keeps the deduction math honest.
 */
final readonly class UpdateIngredientAction
{
    /** LAUNCH item kind, F6. */
    public const KIND_CHANGE_MESSAGE = 'Remove its pack sizes and count container before changing the kind.';

    private const MUTABLE_FIELDS = [
        'name',
        'name_ar',
        'unit',
        // Fix order B-1 (M4) — piece_unit_label / piece_unit_label_ar /
        // units_per_piece are the count container's MIRROR: they change only
        // through count_container_uuid (CountContainerMirror), never on their
        // own. allow_fractional_pieces changes only together with
        // count_container_uuid (UpdateIngredientRequest refuses it otherwise).
        'allow_fractional_pieces',
        // LAUNCH review add-on (A1) — default_unit_cost is no longer typed:
        // it comes from purchases (UpdateIngredientRequest refuses a change).
        'min_stock_threshold',
        'primary_supplier_id',
        'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Ingredient $ingredient, array $attributes, User $actor): Ingredient
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $ingredient->company_id !== $companyId) {
            abort(404);
        }

        // Supplier ownership re-check on update.
        if (array_key_exists('primary_supplier_id', $attributes) && ! empty($attributes['primary_supplier_id'])) {
            $supplierOk = Supplier::query()
                ->where('id', $attributes['primary_supplier_id'])
                ->where('company_id', $companyId)
                ->exists();
            if (! $supplierOk) {
                throw new RuntimeException('The selected supplier does not belong to your company.');
            }
        }

        // Unit-change guard — explained in the class docblock. Flipping the
        // unit without rescaling recipe / add-on lines would silently
        // mis-deduct them at sale (0.250 authored as kg, then read as grams,
        // deducts 1000x too little). The rule lives in IngredientUnitLock so
        // the ingredient list can lock the kind up front (LAUNCH item kind).
        if (array_key_exists('unit', $attributes) && $attributes['unit'] !== $ingredient->unit?->value) {
            if (IngredientUnitLock::isLocked($ingredient)) {
                throw new RuntimeException(IngredientUnitLock::MESSAGE);
            }
            // LAUNCH item kind, F6 — a pack size or the count container holds
            // an amount OF THE STORED UNIT ("crate" = 12000 ml); after a kind
            // change it would silently mean 12000 g. Refuse until they are
            // removed (a container cleared in this same save counts as removed).
            // Fix order B-1 (M4) — the count container is removed in the same
            // save by count_container_uuid: null (the mirror never changes alone).
            $containerLabel = array_key_exists('count_container_uuid', $attributes) && ($attributes['count_container_uuid'] === null || $attributes['count_container_uuid'] === '')
                ? null
                : $ingredient->piece_unit_label;
            if ($ingredient->altUnits()->exists() || ($containerLabel !== null && trim((string) $containerLabel) !== '')) {
                throw new RuntimeException(self::KIND_CHANGE_MESSAGE);
            }
        }

        return DB::transaction(function () use ($ingredient, $attributes, $actor, $companyId): Ingredient {
            $changes = [];

            // LAUNCH review add-on (A4) — the SKU: a typed code (unique across
            // ingredients and products), or blank = keep / generate one.
            if (array_key_exists('sku', $attributes)) {
                ItemCodes::lockSku($companyId);
                $sku = ItemCodes::normalize($attributes['sku']);
                if ($sku === '') {
                    $sku = $ingredient->sku !== null && trim((string) $ingredient->sku) !== ''
                        ? (string) $ingredient->sku
                        : ItemCodes::nextSku($companyId, ItemCodes::PREFIX_INGREDIENT);
                } elseif (($owner = ItemCodes::skuOwner($companyId, $sku, (int) $ingredient->id)) !== null) {
                    throw new RuntimeException(ItemCodes::skuMessage($owner));
                }
                if ($sku !== (string) $ingredient->sku) {
                    $changes['sku'] = ['old' => $ingredient->sku, 'new' => $sku];
                    $ingredient->sku = $sku;
                }
            }

            // A3 — the count container: one of the item's containers (or none),
            // mirrored into the piece_* columns devices read.
            if (array_key_exists('count_container_uuid', $attributes)) {
                $uuid = $attributes['count_container_uuid'];
                $container = ($uuid === null || $uuid === '') ? null : Containers::findByUuid($ingredient, (string) $uuid);
                if ($container === null && $uuid !== null && $uuid !== '') {
                    throw new RuntimeException('The count container must be one of this item\'s containers.');
                }
                foreach (['piece_unit_label', 'piece_unit_label_ar', 'units_per_piece'] as $mirrored) {
                    unset($attributes[$mirrored]);
                }
                $changes += CountContainerMirror::apply($ingredient, $container);
            }

            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $ingredient->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum ? $oldValue->value : $oldValue;

                // Money + threshold columns are decimal-cast → strings.
                if (in_array($field, ['default_unit_cost', 'min_stock_threshold'], true)) {
                    $sameValue = (string) $oldComparable === (string) $newValue;
                } else {
                    $sameValue = $oldComparable == $newValue;
                }
                if ($sameValue) {
                    continue;
                }

                $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                $ingredient->{$field} = $newValue;
            }

            if ($changes === []) {
                return $ingredient->fresh();
            }

            $ingredient->save();
            // LAUNCH-P3 — prep items cost through this ingredient.
            PrepGraph::forget($companyId);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Ingredient::class,
                auditableId: $ingredient->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $ingredient->fresh();
        });
    }
}
