<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\BranchStock;
use App\Models\Ingredient;
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
 * any movement exists for this ingredient, because every
 * historical movement was recorded in the original unit and
 * "1.000" of kg means something completely different than
 * "1.000" of g. Forcing the merchant to delete and recreate
 * (which they can't if stock exists) keeps the math honest.
 */
final readonly class UpdateIngredientAction
{
    private const MUTABLE_FIELDS = [
        'name',
        'name_ar',
        'unit',
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
                    ->exists();
            if ($hasHistory) {
                throw new RuntimeException(
                    'Cannot change the unit of an ingredient that already has stock or movements. Delete the existing stock first, then create a new ingredient with the new unit.',
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
