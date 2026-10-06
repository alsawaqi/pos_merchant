<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use App\Models\ProductRecipe;
use App\Support\Catalogue\OrderTypes;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * LAUNCH-P3 P3-1 / P3-2 — one recipe (product or prep item) as a map of
 * normalised lines, for:
 *
 *   - change detection (base quantity AND the entered form; a pre-P3 line
 *     with NULL entered columns equals the same amount typed in its base);
 *   - the pre-edit snapshot stored in pos_product_recipe_versions.recipe_json
 *     (the keys pos_api reads — ingredient_id, quantity, unit — keep their
 *     meaning: BASE quantity and base unit; the entered keys are additions);
 *   - the readable before → after list in the audit row and the history.
 *
 * LAUNCH packaging add-on — a product recipe line also carries its "Used for"
 * ticks (`order_types`, {@see OrderTypes}). The same ingredient may sit on
 * several lines whose ticks do not overlap, so the map is keyed by
 * "ingredient id:mask" ({@see self::key()}). A tick change is a real change
 * (a version row, the gate, the audit diff). The mask is written into the
 * version snapshot only when it is not 15, so untagged recipes snapshot
 * exactly as before; prep-item lines are always 15.
 *
 * @phpstan-type Line array{ingredient_id: int, ingredient_name: string, is_prep: bool, quantity: string, unit: string, entered_unit: string, entered_quantity: string, entered_unit_label: string, unit_cost_at_time: string, order_types: int}
 */
final class RecipeLineChanges
{
    /**
     * @param  Collection<int, ProductRecipe>  $lines  with ingredient loaded
     * @return array<string, Line>
     */
    public static function fromProductLines(Collection $lines, RecipeQuantity $quantities): array
    {
        $out = [];
        foreach ($lines as $line) {
            [$enteredUnit, $enteredQuantity] = self::storedEntered($line->ingredient, (string) $line->quantity, $line->entered_unit, $line->entered_quantity, $quantities);
            $mask = OrderTypes::read($line->order_types ?? null);
            $out[self::key((int) $line->ingredient_id, $mask)] = self::line(
                $line->ingredient,
                (int) $line->ingredient_id,
                (string) $line->quantity,
                $line->unit_at_set?->value ?? $line->ingredient?->unit?->value ?? '',
                $enteredUnit,
                $enteredQuantity,
                $quantities,
                $mask,
            );
        }

        return $out;
    }

    /** LAUNCH packaging add-on — the map key of a line: "ingredient id:mask". */
    public static function key(int $ingredientId, int $orderTypes = OrderTypes::ALL): string
    {
        return $ingredientId.':'.$orderTypes;
    }

    /**
     * The distinct ingredient ids of a set of lines (the audit's ingredient_ids).
     *
     * @param  array<string, Line>  $lines
     * @return list<int>
     */
    public static function ingredientIds(array $lines): array
    {
        return array_values(array_unique(array_map(static fn (array $l): int => $l['ingredient_id'], array_values($lines))));
    }

    /**
     * Fix order 1, L7 — a STORED line's entered form, as the editor shows it
     * ({@see RecipeQuantity::display()}): once its extra unit was re-sized or
     * deleted (or the piece ratio moved) it no longer converts to the stored
     * base quantity and the line reopens in the base unit. Compare it that
     * way too, so sending back the untouched reopened line is a no-op — not a
     * phantom "2 box → 24 piece" version blamed on whoever saved next.
     *
     * @return array{0: ?string, 1: string|int|float|null}
     */
    public static function storedEntered(?Ingredient $ingredient, string $baseQuantity, ?string $enteredUnit, string|int|float|null $enteredQuantity, RecipeQuantity $quantities): array
    {
        if ($ingredient === null || $enteredUnit === null || $enteredUnit === '' || $enteredQuantity === null || $enteredQuantity === '') {
            return [$enteredUnit, $enteredQuantity];
        }

        // LAUNCH review add-on (A2) — compared as the editor reopens it: a
        // container named by its old name (or '@piece') reads back as the
        // container's token, so re-saving it untouched stays a no-op.
        $shown = $quantities->display($ingredient, $baseQuantity, $enteredUnit, $enteredQuantity);

        return $shown['entered']
            ? [$shown['unit'], $enteredQuantity]
            : [null, null];
    }

    /**
     * @param  Collection<int, IngredientRecipe>  $lines  with ingredient loaded
     * @return array<string, Line>
     */
    public static function fromPrepLines(Collection $lines, RecipeQuantity $quantities): array
    {
        $out = [];
        foreach ($lines as $line) {
            [$enteredUnit, $enteredQuantity] = self::storedEntered($line->ingredient, (string) $line->quantity, $line->entered_unit, $line->entered_quantity, $quantities);
            $out[self::key((int) $line->ingredient_id)] = self::line(
                $line->ingredient,
                (int) $line->ingredient_id,
                (string) $line->quantity,
                $line->ingredient?->unit?->value ?? '',
                $enteredUnit,
                $enteredQuantity,
                $quantities,
            );
        }

        return $out;
    }

    /**
     * @param  list<array{ingredient: Ingredient, quantity: string, entered_unit: string, entered_quantity: string, order_types?: int}>  $resolved
     * @return array<string, Line>
     */
    public static function fromResolved(array $resolved, RecipeQuantity $quantities): array
    {
        $out = [];
        foreach ($resolved as $line) {
            $ingredient = $line['ingredient'];
            $mask = OrderTypes::read($line['order_types'] ?? null);
            $out[self::key((int) $ingredient->id, $mask)] = self::line(
                $ingredient,
                (int) $ingredient->id,
                $line['quantity'],
                $ingredient->unit?->value ?? '',
                $line['entered_unit'],
                $line['entered_quantity'],
                $quantities,
                $mask,
            );
        }

        return $out;
    }

    /**
     * @param  array<string, Line>  $a
     * @param  array<string, Line>  $b
     */
    public static function same(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $id => $line) {
            if (! isset($b[$id]) || ! self::sameLine($line, $b[$id])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The pre-edit snapshot for pos_product_recipe_versions.recipe_json.
     *
     * @param  array<string, Line>  $lines
     * @return list<array<string, mixed>>
     */
    public static function snapshot(array $lines): array
    {
        return array_values(array_map(static fn (array $l): array => [
            // The pre-P3 keys (pos_api reads ingredient_id / quantity / unit).
            'ingredient_id' => $l['ingredient_id'],
            'ingredient_name' => $l['ingredient_name'],
            'quantity' => $l['quantity'],
            'unit' => $l['unit'],
            'unit_cost_at_time' => $l['unit_cost_at_time'],
            // LAUNCH-P3 additions.
            'entered_unit' => $l['entered_unit'],
            'entered_quantity' => $l['entered_quantity'],
            'entered_unit_label' => $l['entered_unit_label'],
            'is_prep' => $l['is_prep'],
        ] + (($l['order_types'] ?? OrderTypes::ALL) !== OrderTypes::ALL
            // LAUNCH packaging add-on — pos_api's RecipeInForce reads it
            // (absent = every type), so it is written only when not 15.
            ? ['order_types' => $l['order_types']]
            : []), $lines));
    }

    /**
     * Lines from a stored snapshot (pre-P3 snapshots have no entered keys:
     * they read as entered in the base unit; snapshots without order_types
     * read as every order type).
     *
     * @param  array<int, array<string, mixed>>|null  $snapshot
     * @return array<string, Line>
     */
    public static function fromSnapshot(?array $snapshot): array
    {
        $out = [];
        foreach ($snapshot ?? [] as $row) {
            if (! is_array($row) || ! isset($row['ingredient_id'])) {
                continue;
            }
            $unit = (string) ($row['unit'] ?? '');
            $quantity = (string) StockDecimal::quantity((string) ($row['quantity'] ?? '0'));
            $enteredUnit = isset($row['entered_unit']) && $row['entered_unit'] !== '' ? (string) $row['entered_unit'] : $unit;
            $enteredQuantity = isset($row['entered_quantity']) && $row['entered_quantity'] !== ''
                ? self::short((string) $row['entered_quantity'])
                : self::short($quantity);
            $mask = OrderTypes::read($row['order_types'] ?? null);
            $out[self::key((int) $row['ingredient_id'], $mask)] = [
                'ingredient_id' => (int) $row['ingredient_id'],
                'ingredient_name' => (string) ($row['ingredient_name'] ?? ''),
                'is_prep' => (bool) ($row['is_prep'] ?? false),
                'quantity' => $quantity,
                'unit' => $unit,
                'entered_unit' => $enteredUnit,
                'entered_quantity' => $enteredQuantity,
                'entered_unit_label' => (string) ($row['entered_unit_label'] ?? ($enteredUnit === '@piece' ? 'piece' : $enteredUnit)),
                'unit_cost_at_time' => (string) ($row['unit_cost_at_time'] ?? '0.000'),
                'order_types' => $mask,
            ];
        }

        return $out;
    }

    /**
     * Every line added, removed or changed, before → after in the entered unit.
     *
     * LAUNCH packaging add-on — an ingredient on ONE line before and after
     * whose amount or ticks moved is "changed"; with several lines of one
     * ingredient, lines are matched by their ticks. A change row carries
     * before_order_types / after_order_types only when a side is not 15.
     *
     * @param  array<string, Line>  $before
     * @param  array<string, Line>  $after
     * @return list<array<string, mixed>>
     */
    public static function diff(array $before, array $after): array
    {
        $countBefore = [];
        $firstBefore = [];
        foreach ($before as $line) {
            $id = $line['ingredient_id'];
            $countBefore[$id] = ($countBefore[$id] ?? 0) + 1;
            $firstBefore[$id] ??= $line;
        }
        $countAfter = [];
        foreach ($after as $line) {
            $countAfter[$line['ingredient_id']] = ($countAfter[$line['ingredient_id']] ?? 0) + 1;
        }
        $single = static fn (int $id): bool => ($countBefore[$id] ?? 0) === 1 && ($countAfter[$id] ?? 0) === 1;

        $changes = [];
        foreach ($after as $key => $line) {
            $old = $single($line['ingredient_id']) ? $firstBefore[$line['ingredient_id']] : ($before[$key] ?? null);
            if ($old === null) {
                $changes[] = self::change($line, 'added', null, $line);
            } elseif (! self::sameLine($old, $line)) {
                $changes[] = self::change($line, 'changed', $old, $line);
            }
        }
        foreach ($before as $key => $line) {
            if (! isset($after[$key]) && ! $single($line['ingredient_id'])) {
                $changes[] = self::change($line, 'removed', $line, null);
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, Line>  $lines
     * @return list<string> "Milk: 150 ml" ("Napkin: 3 piece [To go, Delivery]" when not every type)
     */
    public static function readable(array $lines): array
    {
        return array_values(array_map(static fn (array $l): string => $l['ingredient_name'].': '.self::amount($l)
            .(($l['order_types'] ?? OrderTypes::ALL) !== OrderTypes::ALL ? ' ['.OrderTypes::label($l['order_types']).']' : ''), $lines));
    }

    /** @param Line $line */
    public static function amount(array $line): string
    {
        return $line['entered_quantity'].' '.$line['entered_unit_label'];
    }

    /** @param Line $line */
    public static function baseAmount(array $line): string
    {
        return self::short($line['quantity']).' '.$line['unit'];
    }

    /**
     * @return Line
     */
    private static function line(
        ?Ingredient $ingredient,
        int $ingredientId,
        string $quantity,
        string $baseUnit,
        ?string $enteredUnit,
        string|int|float|null $enteredQuantity,
        RecipeQuantity $quantities,
        int $orderTypes = OrderTypes::ALL,
    ): array {
        $quantity = (string) StockDecimal::quantity($quantity);
        $hasEntered = $enteredUnit !== null && $enteredUnit !== '' && $enteredQuantity !== null && $enteredQuantity !== '';
        // A line without an entered form (pre-P3) reads as typed in the
        // ingredient's base unit — the unit its quantity is stored in.
        $unit = $hasEntered ? (string) $enteredUnit : (string) ($ingredient?->unit?->value ?? $baseUnit);
        $isPrep = (bool) ($ingredient?->is_prep ?? false);

        return [
            'ingredient_id' => $ingredientId,
            'ingredient_name' => (string) ($ingredient?->name ?? ''),
            'is_prep' => $isPrep,
            'quantity' => $quantity,
            'unit' => $baseUnit,
            'entered_unit' => $unit,
            'entered_quantity' => self::short($hasEntered ? (string) $enteredQuantity : $quantity),
            'entered_unit_label' => $ingredient !== null ? $quantities->label($ingredient, $unit) : $unit,
            // The cost at the edit (prep items: derived from their recipe).
            'unit_cost_at_time' => $ingredient === null
                ? '0.000'
                : ($isPrep
                    ? PrepGraph::forCompany((int) $ingredient->company_id)->unitCost($ingredientId)
                    : (string) ($ingredient->default_unit_cost ?? '0.000')),
            'order_types' => $orderTypes,
        ];
    }

    /**
     * @param  Line  $a
     * @param  Line  $b
     */
    private static function sameLine(array $a, array $b): bool
    {
        return BigDecimal::of($a['quantity'])->isEqualTo($b['quantity'])
            && $a['entered_unit'] === $b['entered_unit']
            && BigDecimal::of($a['entered_quantity'])->isEqualTo($b['entered_quantity'])
            && ($a['order_types'] ?? OrderTypes::ALL) === ($b['order_types'] ?? OrderTypes::ALL);
    }

    /**
     * @param  Line  $line
     * @param  Line|null  $old
     * @param  Line|null  $new
     * @return array<string, mixed>
     */
    private static function change(array $line, string $change, ?array $old, ?array $new): array
    {
        $row = [
            'ingredient_id' => $line['ingredient_id'],
            'ingredient' => $line['ingredient_name'],
            'change' => $change,
            'before' => $old !== null ? self::amount($old) : null,
            'after' => $new !== null ? self::amount($new) : null,
            'before_base' => $old !== null ? self::baseAmount($old) : null,
            'after_base' => $new !== null ? self::baseAmount($new) : null,
        ];
        // LAUNCH packaging add-on — the ticks, only when a side is not every type.
        $beforeTypes = $old !== null ? ($old['order_types'] ?? OrderTypes::ALL) : null;
        $afterTypes = $new !== null ? ($new['order_types'] ?? OrderTypes::ALL) : null;
        if (($beforeTypes !== null && $beforeTypes !== OrderTypes::ALL) || ($afterTypes !== null && $afterTypes !== OrderTypes::ALL)) {
            $row['before_order_types'] = $beforeTypes;
            $row['after_order_types'] = $afterTypes;
        }

        return $row;
    }

    private static function short(string $value): string
    {
        return RecipeQuantity::trim(BigDecimal::of(trim($value) === '' ? '0' : trim($value)));
    }
}
