<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\ProductRecipe;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\MerchantTenantContext;
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
    private const MUTABLE_FIELDS = [
        'name',
        'name_ar',
        'unit',
        // Phase A — piece config IS mutable with history: stock
        // stays in the primary unit, so changing the piece ratio
        // only affects FUTURE entry conversions (each purchase
        // batch freezes its own ratio anyway).
        'piece_unit_label',
        'piece_unit_label_ar',
        'units_per_piece',
        'allow_fractional_pieces',
        'default_unit_cost',
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

        // Unit-change guard — explained in the class docblock.
        if (array_key_exists('unit', $attributes) && $attributes['unit'] !== $ingredient->unit?->value) {
            $hasHistory = StockMovement::query()
                ->where('ingredient_id', $ingredient->id)
                ->exists()
                || BranchStock::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->where('quantity', '!=', '0.000')
                    ->exists()
                // A recipe / add-on consumption line stores its quantity in the
                // ingredient's CURRENT base unit (the portal converts at entry).
                // Flipping the unit without rescaling those lines would silently
                // mis-deduct them at sale — e.g. 0.250 authored as kg, then read
                // as grams, deducts 1000x too little. Block the flip while any
                // recipe/add-on still references the ingredient.
                || ProductRecipe::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->exists()
                || AddOnConsumption::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->exists()
                // Legacy single-ingredient add-on (pos_addons.ingredient_id /
                // ingredient_qty) — still read by the sale-time deduction
                // pipeline, so its base-unit qty must be protected too.
                || AddOn::query()
                    ->where('ingredient_id', $ingredient->id)
                    ->exists();
            if ($hasHistory) {
                throw new RuntimeException(
                    'Cannot change the unit of an ingredient that already has stock, movements, or recipe/add-on usage. Remove those references first, then create a new ingredient with the new unit.',
                );
            }
        }

        return DB::transaction(function () use ($ingredient, $attributes, $actor, $companyId): Ingredient {
            $changes = [];
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
