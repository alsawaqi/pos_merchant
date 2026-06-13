<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Pos\Inventory\IngredientUnitConverter;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
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
 * Audit event: catalogue.addon.consumption_updated.
 */
final readonly class SyncAddOnConsumptionAction
{
    private const MAX_LINES = 20;

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private IngredientUnitConverter $units,
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

        // Normalised shape for the no-op diff: key = kind:ref:direction.
        $newShape = collect($resolved)->mapWithKeys(static fn (array $l): array => [
            ($l['ingredient_id'] !== null ? 'i:'.$l['ingredient_id'] : 'p:'.$l['component_product_id']).':'.$l['direction'] => $l['quantity'],
        ])->sortKeys();

        $currentShape = $addon->consumptionLines()
            ->get()
            ->mapWithKeys(static fn (AddOnConsumption $c): array => [
                ($c->ingredient_id !== null ? 'i:'.$c->ingredient_id : 'p:'.$c->component_product_id).':'.$c->direction => (string) $c->quantity,
            ])->sortKeys();

        // No-op BEFORE the kind guards: an untouched set must never block an
        // unrelated edit (the option modal re-sends the full set on every
        // save — a product whose type changed since the lines were written
        // would otherwise 422 a pure rename).
        if ($newShape->toArray() === $currentShape->toArray()) {
            return $addon->fresh();
        }

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
     * Kind guards, run only when the set actually changes: what KINDS of
     * product an option may consume, and the double-consumption trap.
     *
     * @param  array<int, array{ingredient_id: ?int, component_product_id: ?int, direction: string, quantity: string, unit: ?string}>  $resolved
     * @param  \Illuminate\Support\Collection<string, Product>  $products
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
     * @return array{0: array<int, array{ingredient_id: ?int, component_product_id: ?int, direction: string, quantity: string, unit: ?string}>, 1: \Illuminate\Support\Collection<string, Product>}
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
                // Convert-at-entry, store-in-base (the recipe convention) —
                // throws on an unknown unit for this ingredient.
                $qty = $this->units->toBase($ingredient, $line['quantity'], $line['unit'] ?? null);
                if ($qty <= 0) {
                    throw new RuntimeException('Stock-usage quantities must be positive.');
                }
                $key = 'i:'.$ingredient->id.':'.$direction;
                $entry = [
                    'ingredient_id' => (int) $ingredient->id,
                    'component_product_id' => null,
                    'direction' => $direction,
                    'quantity' => number_format($qty, 3, '.', ''),
                    'unit' => $ingredient->unit?->value,
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
