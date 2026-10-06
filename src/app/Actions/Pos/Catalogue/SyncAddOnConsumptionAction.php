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
use App\Support\Catalogue\OrderTypes;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\ExplodedPrecision;
use App\Support\Recipes\PrepGraph;
use App\Support\Recipes\RecipeEditGate;
use App\Support\Recipes\RecipeLineChanges;
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
 * direction add|remove. LAUNCH packaging add-on — each line has "Used
 * for" ticks (pos_api takes it only for those order types); one ref may
 * sit on several lines of one direction only when their ticks do not
 * overlap (the DB uniques gave way to this app rule). Removals are validated shallowly here — whether
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

        // LAUNCH packaging add-on — the stored "Used for" ticks per
        // kind:ref:direction (a line sent without ticks keeps them).
        $stored = [];
        foreach ($addon->consumptionLines()->get(['ingredient_id', 'component_product_id', 'direction', 'order_types']) as $row) {
            $stored[self::refKey($row->ingredient_id, $row->component_product_id, (string) $row->direction)][] = OrderTypes::read($row->order_types);
        }

        [$resolved, $products] = $this->resolveLines($lines, $companyId, $stored);

        // Normalised shape for the no-op diff: key = kind:ref:direction (and
        // the ticks when not every order type); value = the base quantity +
        // (ingredient lines, LAUNCH-P3) the entered form.
        $newShape = collect($resolved)->mapWithKeys(static fn (array $l): array => [
            self::shapeKey($l['ingredient_id'], $l['component_product_id'], $l['direction'], $l['order_types']) => self::shapeValue(
                $l['quantity'],
                $l['unit'],
                $l['entered_unit'],
                $l['entered_quantity'],
            ),
        ])->sortKeys();

        // Fix order 1, L7 — a stored entered form that no longer converts (its
        // extra unit was re-sized or deleted) reads as the base, like the
        // modal reopens it: sending that back untouched stays a no-op.
        $currentShape = $addon->consumptionLines()
            ->with('ingredient.altUnits')
            ->get()
            ->mapWithKeys(function (AddOnConsumption $c): array {
                [$enteredUnit, $enteredQuantity] = $c->ingredient_id !== null
                    ? RecipeLineChanges::storedEntered($c->ingredient, (string) $c->quantity, $c->entered_unit, $c->entered_quantity, $this->quantities)
                    : [$c->entered_unit, $c->entered_quantity];

                return [
                    self::shapeKey($c->ingredient_id, $c->component_product_id, (string) $c->direction, OrderTypes::read($c->order_types)) => self::shapeValue(
                        (string) $c->quantity,
                        $c->ingredient_id !== null ? $c->unit : null,
                        $enteredUnit,
                        $enteredQuantity,
                    ),
                ];
            })->sortKeys();

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

        // LAUNCH-P3 M1-a — a prep line must still record accurately once it is
        // exploded per ONE selection (the copy pos_api freezes at sale).
        ExplodedPrecision::assertOptionRecordable(
            PrepGraph::forCompany($companyId),
            array_map(static fn (array $l): array => [
                'ingredient_id' => (int) $l['ingredient_id'],
                'direction' => $l['direction'],
                'quantity' => $l['quantity'],
                'order_types' => $l['order_types'],
            ], array_values(array_filter($resolved, static fn (array $l): bool => $l['ingredient_id'] !== null))),
            (string) $addon->name,
        );

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
                    // LAUNCH packaging add-on — "Used for" (15 = every type).
                    'order_types' => $line['order_types'],
                ]);
            }

            // Delta visibility: the device config delta keys on the
            // GROUP's updated_at; the addon's own bump feeds full syncs.
            $addon->touch();
            $group = $addon->group()->withTrashed()->first();
            $group?->touch();
            // LAUNCH packaging add-on — an option of a product's own group:
            // that product is touched too.
            if ($group !== null && $group->owner_product_id !== null) {
                Product::query()->whereKey($group->owner_product_id)->first()?->touch();
            }

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

    /** kind:ref:direction — one item in one direction. */
    private static function refKey(int|string|null $ingredientId, int|string|null $productId, string $direction): string
    {
        return ($ingredientId !== null ? 'i:'.$ingredientId : 'p:'.$productId).':'.$direction;
    }

    /** The shape key: refKey, plus ":mask" when the line is not for every order type. */
    private static function shapeKey(int|string|null $ingredientId, int|string|null $productId, string $direction, int $orderTypes): string
    {
        $key = self::refKey($ingredientId, $productId, $direction);

        return $orderTypes === OrderTypes::ALL ? $key : $key.':'.$orderTypes;
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
     * LAUNCH packaging add-on — with each line's "Used for" ticks (as sent, or
     * the stored ticks of that item's only line in that direction). The same
     * item in one direction may sit on several lines only when their ticks do
     * not overlap (tester call 3).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, list<int>>  $stored  kind:ref:direction => stored masks
     * @return array{0: array<int, array{ingredient_id: ?int, component_product_id: ?int, direction: string, quantity: string, unit: ?string, entered_unit: ?string, entered_quantity: ?string, order_types: int}>, 1: Collection<string, Product>}
     */
    private function resolveLines(array $lines, int $companyId, array $stored = []): array
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
        $ticks = [];
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
                $key = self::refKey($ingredient->id, null, $direction);
                $name = (string) $ingredient->name;
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
                // LAUNCH-P3 P3-1 — pieces keep 3 decimals: refuse a finer
                // amount rather than store it rounded (or as 0).
                if (round((float) $line['quantity'], 3) != (float) $line['quantity']) {
                    throw new RuntimeException(sprintf('"%s": pieces keep at most 3 decimal places.', $product->name));
                }
                $key = self::refKey(null, $product->id, $direction);
                $name = (string) $product->name;
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

            $entry['order_types'] = OrderTypes::resolve($line['order_types'] ?? null, $key, $stored);
            $ticks[] = ['key' => $key, 'mask' => $entry['order_types'], 'name' => $name];
            $resolved[] = $entry;
        }
        OrderTypes::assertNoOverlap($ticks);

        return [$resolved, $products];
    }
}
