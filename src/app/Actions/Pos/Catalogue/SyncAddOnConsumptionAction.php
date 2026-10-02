<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\RecipeEditGate;
use App\Support\Recipes\RecipeQuantity;
use App\Support\StockDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PD3b — atomically replace an add-on option's stock-usage lines
 * (the UpdateProductRecipeAction pattern, no versioning).
 *
 * Each line is ingredient XOR product:
 *   - ingredient lines convert-at-entry to the ingredient's BASE unit
 *     (IngredientUnitConverter, the recipe convention) so the pay-time
 *     merge with recipe_snapshot_json is unit-consistent;
 *   - product lines must be piece-counted things an option can plausibly
 *     consume: packaging physical items (internal, purpose packaging or
 *     legacy NULL), prepared COOKED products, or bought-in unit products.
 *     Branch-use ('general') items and recipe-only/untracked products
 *     are refused — they have no per-branch piece stock to consume.
 *
 * direction add|remove, at most one of each per ref per option (the DB
 * uniques back this up). Removals are validated shallowly here — whether
 * they exceed the parent recipe is unknowable at write time (a shared
 * group attaches to many products); the pay-time engine clamps at zero.
 *
 * Writes touch the addon AND its group: the device config delta tracks
 * addon GROUPS, so an untouched group would hide the change from
 * delta-syncing devices until the next full sync.
 *
 * LAUNCH-P3:
 *   - P3-1 an ingredient line keeps how it was typed (entered_unit /
 *     entered_quantity, {@see RecipeQuantity}); quantity + unit stay the
 *     BASE values the device reads; an amount that rounds to 0 in the base
 *     unit is refused. The ingredient may be a prep item (P3-4).
 *   - P3-3 changing the lines needs "Edit recipes"; an unchanged set (the
 *     modal re-sends it on every save) never does.
 *
 * Audit event: catalogue.addon.consumption_updated.
 */
final readonly class SyncAddOnConsumptionAction
{
    private const MAX_LINES = 20;

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private RecipeQuantity $quantities,
    ) {}

    /**
     * @param  array<int, array{type: string, ingredient_uuid?: ?string, product_uuid?: ?string, direction?: ?string, quantity: numeric-string|float|int, unit?: ?string}>  $lines
     */
    public function handle(AddOn $addon, array $lines, User $actor): AddOn
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $addon->company_id !== $companyId) {
            abort(404);
        }
        if (count($lines) > self::MAX_LINES) {
            throw new RuntimeException('An option can carry at most '.self::MAX_LINES.' stock-usage lines.');
        }

        [$resolved, $products] = $this->resolveLines($lines, $companyId);

        // Normalised shape for the no-op diff: key = kind:ref:direction; value
        // = the base quantity + (ingredient lines, LAUNCH-P3) the entered form.
        $newShape = collect($resolved)->mapWithKeys(static fn (array $l): array => [
            ($l['ingredient_id'] !== null ? 'i:'.$l['ingredient_id'] : 'p:'.$l['component_product_id']).':'.$l['direction'] => self::shapeValue(
                $l['quantity'],
                $l['unit'],
                $l['entered_unit'],
                $l['entered_quantity'],
            ),
        ])->sortKeys();

        $currentShape = $addon->consumptionLines()
            ->get()
            ->mapWithKeys(static fn (AddOnConsumption $c): array => [
                ($c->ingredient_id !== null ? 'i:'.$c->ingredient_id : 'p:'.$c->component_product_id).':'.$c->direction => self::shapeValue(
                    (string) $c->quantity,
                    $c->ingredient_id !== null ? $c->unit : null,
                    $c->entered_unit,
                    $c->entered_quantity,
                ),
            ])->sortKeys();

        // No-op BEFORE the kind guards: an untouched set must never block an
        // unrelated edit (the option modal re-sends the full set on every
        // save — a product whose type changed since the lines were written
        // would otherwise 422 a pure rename).
        if ($newShape->toArray() === $currentShape->toArray()) {
            return $addon->fresh();
        }

        // LAUNCH-P3 P3-3 — the lines DO change: "Edit recipes" required.
        RecipeEditGate::ensure($actor);

        $this->assertLineKinds($resolved, $products, $addon);

        return DB::transaction(function () use ($addon, $resolved, $actor, $companyId, $currentShape, $newShape): AddOn {
            $addon->consumptionLines()->delete();
            foreach ($resolved as $idx => $line) {
                AddOnConsumption::query()->create([
                    'add_on_id' => $addon->id,
                    'ingredient_id' => $line['ingredient_id'],
                    'component_product_id' => $line['component_product_id'],
                    'direction' => $line['direction'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'display_order' => $idx,
                    'entered_unit' => $line['entered_unit'],
                    'entered_quantity' => $line['entered_quantity'],
                ]);
            }

            // Delta visibility: the device config delta keys on the
            // GROUP's updated_at; the addon's own bump feeds full syncs.
            $addon->touch();
            $addon->group()->withTrashed()->first()?->touch();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.addon.consumption_updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: AddOn::class,
                auditableId: $addon->id,
                oldValues: ['lines' => $currentShape->toArray()],
                newValues: ['lines' => $newShape->toArray()],
            ));

            return $addon->fresh();
        });
    }

    /**
     * The comparable (and audited) value of one line: the base quantity, and
     * for an ingredient line its base unit and the amount as entered — a
     * pre-P3 line (NULL entered columns) equals the same amount typed in the
     * base unit, so re-sending an untouched set stays a no-op.
     */
    private static function shapeValue(string $quantity, ?string $baseUnit, ?string $enteredUnit, string|int|float|null $enteredQuantity): string
    {
        $qty = (string) StockDecimal::quantity($quantity);
        if ($baseUnit === null) {
            return $qty;
        }

        return $qty.' '.$baseUnit.' (entered '.RecipeQuantity::enteredKey($baseUnit, $qty, $enteredUnit, $enteredQuantity).')';
    }

    /**
     * Kind guards, run only when the set actually changes: what KINDS of
     * product an option may consume, and the double-consumption trap.
     *
     * @param  array<int, array{ingredient_id: ?int, component_product_id: ?int, direction: string, quantity: string, unit: ?string, entered_unit: ?string, entered_quantity: ?string}>  $resolved
     * @param  Collection<string, Product>  $products
     */
    private function assertLineKinds(array $resolved, $products, AddOn $addon): void
    {
        $productsById = $products->keyBy('id');

        foreach ($resolved as $line) {
            if ($line['component_product_id'] === null) {
                continue;
            }
            /** @var Product|null $product */
            $product = $productsById[$line['component_product_id']] ?? null;
            if ($product === null) {
                continue;
            }
            if (! in_array($product->stock_mode, ['unit', 'cooked'], true)) {
                throw new RuntimeException(sprintf(
                    '"%s" is not piece-counted — an option can only consume Ready/bought-in or Cooked products.',
                    $product->name,
                ));
            }
            if ($product->internal_purpose === 'general') {
                throw new RuntimeException(sprintf(
                    '"%s" is a branch-use physical item — it cannot be consumed with food.',
                    $product->name,
                ));
            }
            // P-G3 collision: a linked product ALREADY consumes its stock
            // at sale ("the option IS that product") — a usage line for the
            // same product would consume it twice per selection.
            if ($addon->linked_product_id !== null && (int) $addon->linked_product_id === (int) $product->id) {
                throw new RuntimeException(sprintf(
                    '"%s" is already this option\'s linked product — selling the option consumes it once; a stock-usage line would consume it twice.',
                    $product->name,
                ));
            }
        }
    }

    /**
     * Resolves refs (tenant + existence + unit conversion + dedupe) WITHOUT
     * the kind guards — those run in assertLineKinds after the no-op diff.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{0: array<int, array{ingredient_id: ?int, component_product_id: ?int, direction: string, quantity: string, unit: ?string, entered_unit: ?string, entered_quantity: ?string}>, 1: Collection<string, Product>}
     */
    private function resolveLines(array $lines, int $companyId): array
    {
        // Bulk-resolve both ref kinds in one query each.
        $ingredientUuids = [];
        $productUuids = [];
        foreach ($lines as $line) {
            if (($line['type'] ?? '') === 'ingredient') {
                $ingredientUuids[] = (string) ($line['ingredient_uuid'] ?? '');
            } else {
                $productUuids[] = (string) ($line['product_uuid'] ?? '');
            }
        }

        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', array_filter($ingredientUuids))
            ->get()
            ->keyBy('uuid');
        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', array_filter($productUuids))
            ->get()
            ->keyBy('uuid');

        $resolved = [];
        $seen = [];
        foreach ($lines as $line) {
            $type = (string) ($line['type'] ?? '');
            $direction = (string) ($line['direction'] ?? AddOnConsumption::DIRECTION_ADD);

            if ($type === 'ingredient') {
                /** @var Ingredient|null $ingredient */
                $ingredient = $ingredients[(string) ($line['ingredient_uuid'] ?? '')] ?? null;
                if ($ingredient === null) {
                    throw new RuntimeException('One or more ingredients in the stock-usage lines do not belong to your company.');
                }
                if ((float) $line['quantity'] <= 0) {
                    throw new RuntimeException('Stock-usage quantities must be positive.');
                }
                // Convert-at-entry, store-in-base (the recipe convention) —
                // throws on an unknown unit, and (LAUNCH-P3 P3-1) on an
                // amount that would round to 0 in the base unit.
                $amount = $this->quantities->resolve($ingredient, $line['quantity'], $line['unit'] ?? null);
                $key = 'i:'.$ingredient->id.':'.$direction;
                $entry = [
                    'ingredient_id' => (int) $ingredient->id,
                    'component_product_id' => null,
                    'direction' => $direction,
                    // LAUNCH-P2 — ingredient amounts keep 4 decimals.
                    'quantity' => $amount['quantity'],
                    'unit' => $ingredient->unit?->value,
                    'entered_unit' => $amount['entered_unit'],
                    'entered_quantity' => $amount['entered_quantity'],
                ];
            } else {
                /** @var Product|null $product */
                $product = $products[(string) ($line['product_uuid'] ?? '')] ?? null;
                if ($product === null) {
                    throw new RuntimeException('One or more items in the stock-usage lines do not belong to your company.');
                }
                if ((float) $line['quantity'] <= 0) {
                    throw new RuntimeException('Stock-usage quantities must be positive.');
                }
                $key = 'p:'.$product->id.':'.$direction;
                $entry = [
                    'ingredient_id' => null,
                    'component_product_id' => (int) $product->id,
                    'direction' => $direction,
                    'quantity' => number_format((float) $line['quantity'], 3, '.', ''),
                    'unit' => null,
                    'entered_unit' => null,
                    'entered_quantity' => null,
                ];
            }

            if (isset($seen[$key])) {
                throw new RuntimeException('Duplicate stock-usage line — merge same-item lines of the same direction client-side first.');
            }
            $seen[$key] = true;
            $resolved[] = $entry;
        }

        return [$resolved, $products];
    }
}
