<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\ItemBarcode;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\CountContainerMirror;
use App\Support\Inventory\ItemCodes;
use App\Support\Inventory\PackSize;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5a — create an ingredient for the actor's company.
 *
 * Validator on the controller checks (company_id, name)
 * uniqueness. This Action does the atomic write + audit.
 *
 * LAUNCH review add-on:
 *   A1 no cost field: the cost starts at 0 ("No cost yet") and comes only
 *      from purchases (the weighted average).
 *   A2 the containers ("How do you buy it?") are saved in the same
 *      transaction, nested ones after what they hold.
 *   A3 the row marked count_container (or a piece_unit_label +
 *      units_per_piece pair from an older caller, which becomes a container
 *      row) is the count container, mirrored into the piece_* columns.
 *   A4 a blank SKU is generated (ING-0001), under the per-company code lock.
 *   A5 barcodes on the item and on each container.
 *
 * Audit event: inventory.ingredient.created.
 */
final readonly class CreateIngredientAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private CreateIngredientUnitAction $createUnit,
    ) {}

    /**
     * LAUNCH item kind, A3 — optional pack_sizes ("crate holds 12 l") are
     * saved in the SAME transaction through CreateIngredientUnitAction (its
     * rules and audit), each with its factor = the amount in the stored
     * unit. Any refusal rolls the whole ingredient back.
     *
     * @param  array<string, mixed>  $attributes
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
            ItemCodes::lockSku($companyId);
            ItemCodes::lockBarcode($companyId);
            $sku = ItemCodes::normalize($attributes['sku'] ?? null);
            if ($sku === '') {
                $sku = ItemCodes::nextSku($companyId, ItemCodes::PREFIX_INGREDIENT);
            } elseif (($owner = ItemCodes::skuOwner($companyId, $sku)) !== null) {
                throw new RuntimeException(ItemCodes::skuMessage($owner));
            }

            /** @var Ingredient $ingredient */
            $ingredient = Ingredient::query()->create([
                'company_id' => $companyId,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'unit' => $attributes['unit'],
                'allow_fractional_pieces' => $attributes['allow_fractional_pieces'] ?? true,
                // A1 — no typed cost: "No cost yet" until a priced purchase.
                'default_unit_cost' => 0,
                'min_stock_threshold' => $attributes['min_stock_threshold'] ?? null,
                'primary_supplier_id' => $attributes['primary_supplier_id'] ?? null,
                'status' => 'active',
                'sku' => $sku,
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
                    'sku' => $ingredient->sku,
                ],
            ));

            /** @var array<int, IngredientAltUnit> $created */
            $created = [];
            $countContainer = null;
            foreach (array_values($attributes['pack_sizes'] ?? []) as $i => $pack) {
                $containsIndex = $pack['contains_index'] ?? null;
                $unitAttributes = [
                    'name' => $pack['name'],
                    'name_ar' => $pack['name_ar'] ?? null,
                    'sort_order' => $i,
                ];
                if ($containsIndex !== null && $containsIndex !== '') {
                    $content = $created[(int) $containsIndex] ?? null;
                    if ($content === null) {
                        throw new RuntimeException('A container can hold only a container listed above it.');
                    }
                    $unitAttributes['contains_unit_id'] = (int) $content->id;
                    $unitAttributes['contains_quantity'] = $pack['contains_quantity'] ?? null;
                } else {
                    $unitAttributes['factor'] = PackSize::factor($ingredient->unit, $pack['amount'], $pack['unit']);
                }
                $container = $this->createUnit->handle($ingredient, $unitAttributes, $actor);
                $created[$i] = $container;
                if (! empty($pack['count_container'])) {
                    $countContainer = $container;
                }
                $this->barcodes($ingredient, $container, (array) ($pack['barcodes'] ?? []), $actor);
            }

            // A3 — an older caller's piece_unit_label + units_per_piece pair
            // becomes (or links to) a container row: the count container.
            $label = isset($attributes['piece_unit_label']) ? trim((string) $attributes['piece_unit_label']) : '';
            if ($countContainer === null && $label !== '' && isset($attributes['units_per_piece']) && (float) $attributes['units_per_piece'] > 0) {
                $factor = number_format((float) $attributes['units_per_piece'], 4, '.', '');
                $countContainer = collect($created)->first(static fn (IngredientAltUnit $c): bool => mb_strtolower((string) $c->name) === mb_strtolower($label)
                    && abs((float) $c->factor - (float) $factor) < 1e-9 && $c->contains_unit_id === null)
                    ?? $this->createUnit->handle($ingredient, [
                        'name' => mb_substr($label, 0, 32),
                        'name_ar' => $attributes['piece_unit_label_ar'] ?? null,
                        'factor' => $factor,
                        'sort_order' => count($created),
                    ], $actor);
            }
            if ($countContainer !== null) {
                CountContainerMirror::apply($ingredient, $countContainer);
            }

            $this->barcodes($ingredient, null, (array) ($attributes['barcodes'] ?? []), $actor);

            return $ingredient->fresh();
        });
    }

    /**
     * @param  array<int, mixed>  $codes
     */
    private function barcodes(Ingredient $ingredient, ?IngredientAltUnit $container, array $codes, User $actor): void
    {
        foreach ($codes as $code) {
            $barcode = ItemCodes::normalize($code);
            if ($barcode === '') {
                continue;
            }
            if (($owner = ItemCodes::barcodeOwner((int) $ingredient->company_id, $barcode)) !== null) {
                throw new RuntimeException(ItemCodes::barcodeMessage($owner));
            }
            ItemBarcode::query()->create([
                'company_id' => $ingredient->company_id,
                'barcode' => $barcode,
                'ingredient_id' => $ingredient->id,
                'container_id' => $container?->id,
                'created_by_user_id' => $actor->getKey(),
            ]);
        }
    }
}
