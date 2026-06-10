<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5a — create an ingredient for the actor's company.
 *
 * Validator on the controller checks (company_id, name)
 * uniqueness. This Action does the atomic write + audit.
 *
 * Audit event: inventory.ingredient.created.
 */
final readonly class CreateIngredientAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, name_ar?: string|null, unit: string, default_unit_cost?: numeric-string|float|int, min_stock_threshold?: numeric-string|float|int|null, primary_supplier_id?: int|null}  $attributes
     */
    public function handle(array $attributes, User $actor): Ingredient
    {
        $companyId = $this->tenant->requiredId();

        // Cross-tenant defence — if a supplier_id is supplied
        // it MUST belong to the same company.
        if (! empty($attributes['primary_supplier_id'])) {
            $supplierOk = Supplier::query()
                ->where('id', $attributes['primary_supplier_id'])
                ->where('company_id', $companyId)
                ->exists();
            if (! $supplierOk) {
                throw new RuntimeException('The selected supplier does not belong to your company.');
            }
        }

        return DB::transaction(function () use ($attributes, $actor, $companyId): Ingredient {
            /** @var Ingredient $ingredient */
            $ingredient = Ingredient::query()->create([
                'company_id' => $companyId,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'unit' => $attributes['unit'],
                // Phase A — the piece model (Additions §2.3).
                'piece_unit_label' => $attributes['piece_unit_label'] ?? null,
                'piece_unit_label_ar' => $attributes['piece_unit_label_ar'] ?? null,
                'units_per_piece' => $attributes['units_per_piece'] ?? null,
                'allow_fractional_pieces' => $attributes['allow_fractional_pieces'] ?? true,
                'default_unit_cost' => $attributes['default_unit_cost'] ?? 0,
                'min_stock_threshold' => $attributes['min_stock_threshold'] ?? null,
                'primary_supplier_id' => $attributes['primary_supplier_id'] ?? null,
                'status' => 'active',
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Ingredient::class,
                auditableId: $ingredient->id,
                newValues: [
                    'name' => $ingredient->name,
                    'unit' => $ingredient->unit?->value,
                    'default_unit_cost' => (string) $ingredient->default_unit_cost,
                    'min_stock_threshold' => $ingredient->min_stock_threshold !== null ? (string) $ingredient->min_stock_threshold : null,
                ],
            ));

            return $ingredient;
        });
    }
}
