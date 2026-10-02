<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use App\Models\ProductRecipe;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * LAUNCH-P3 P3-1 / P3-2 — one recipe (product or prep item) as a map of
 * normalised lines, keyed by ingredient id, for:
 *
 *   - change detection (base quantity AND the entered form; a pre-P3 line
 *     with NULL entered columns equals the same amount typed in its base);
 *   - the pre-edit snapshot stored in pos_product_recipe_versions.recipe_json
 *     (the keys pos_api reads — ingredient_id, quantity, unit — keep their
 *     meaning: BASE quantity and base unit; the entered keys are additions);
 *   - the readable before → after list in the audit row and the history.
 *
 * @phpstan-type Line array{ingredient_id: int, ingredient_name: string, is_prep: bool, quantity: string, unit: string, entered_unit: string, entered_quantity: string, entered_unit_label: string, unit_cost_at_time: string}
 */
final class RecipeLineChanges
{
    /**
     * @param  Collection<int, ProductRecipe>  $lines  with ingredient loaded
     * @return array<int, Line>
     */
    public static function fromProductLines(Collection $lines, RecipeQuantity $quantities): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[(int) $line->ingredient_id] = self::line(
                $line->ingredient,
                (int) $line->ingredient_id,
                (string) $line->quantity,
                $line->unit_at_set?->value ?? $line->ingredient?->unit?->value ?? '',
                $line->entered_unit,
                $line->entered_quantity,
                $quantities,
            );
        }

        return $out;
    }

    /**
     * @param  Collection<int, IngredientRecipe>  $lines  with ingredient loaded
     * @return array<int, Line>
     */
    public static function fromPrepLines(Collection $lines, RecipeQuantity $quantities): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[(int) $line->ingredient_id] = self::line(
                $line->ingredient,
                (int) $line->ingredient_id,
                (string) $line->quantity,
                $line->ingredient?->unit?->value ?? '',
                $line->entered_unit,
                $line->entered_quantity,
                $quantities,
            );
        }

        return $out;
    }

    /**
     * @param  list<array{ingredient: Ingredient, quantity: string, entered_unit: string, entered_quantity: string}>  $resolved
     * @return array<int, Line>
     */
    public static function fromResolved(array $resolved, RecipeQuantity $quantities): array
    {
        $out = [];
        foreach ($resolved as $line) {
            $ingredient = $line['ingredient'];
            $out[(int) $ingredient->id] = self::line(
                $ingredient,
                (int) $ingredient->id,
                $line['quantity'],
                $ingredient->unit?->value ?? '',
                $line['entered_unit'],
                $line['entered_quantity'],
                $quantities,
            );
        }

        return $out;
    }

    /**
     * @param  array<int, Line>  $a
     * @param  array<int, Line>  $b
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
     * @param  array<int, Line>  $lines
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
        ], $lines));
    }

    /**
     * Lines from a stored snapshot (pre-P3 snapshots have no entered keys:
     * they read as entered in the base unit).
     *
     * @param  array<int, array<string, mixed>>|null  $snapshot
     * @return array<int, Line>
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
            $out[(int) $row['ingredient_id']] = [
                'ingredient_id' => (int) $row['ingredient_id'],
                'ingredient_name' => (string) ($row['ingredient_name'] ?? ''),
                'is_prep' => (bool) ($row['is_prep'] ?? false),
                'quantity' => $quantity,
                'unit' => $unit,
                'entered_unit' => $enteredUnit,
                'entered_quantity' => $enteredQuantity,
                'entered_unit_label' => (string) ($row['entered_unit_label'] ?? ($enteredUnit === '@piece' ? 'piece' : $enteredUnit)),
                'unit_cost_at_time' => (string) ($row['unit_cost_at_time'] ?? '0.000'),
            ];
        }

        return $out;
    }

    /**
     * Every line added, removed or changed, before → after in the entered unit.
     *
     * @param  array<int, Line>  $before
     * @param  array<int, Line>  $after
     * @return list<array{ingredient_id: int, ingredient: string, change: string, before: ?string, after: ?string, before_base: ?string, after_base: ?string}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $id => $line) {
            $old = $before[$id] ?? null;
            if ($old === null) {
                $changes[] = self::change($line, 'added', null, $line);
            } elseif (! self::sameLine($old, $line)) {
                $changes[] = self::change($line, 'changed', $old, $line);
            }
        }
        foreach ($before as $id => $line) {
            if (! isset($after[$id])) {
                $changes[] = self::change($line, 'removed', $line, null);
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, Line>  $lines
     * @return list<string> "Milk: 150 ml"
     */
    public static function readable(array $lines): array
    {
        return array_values(array_map(static fn (array $l): string => $l['ingredient_name'].': '.self::amount($l), $lines));
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
            && BigDecimal::of($a['entered_quantity'])->isEqualTo($b['entered_quantity']);
    }

    /**
     * @param  Line  $line
     * @param  Line|null  $old
     * @param  Line|null  $new
     * @return array{ingredient_id: int, ingredient: string, change: string, before: ?string, after: ?string, before_base: ?string, after_base: ?string}
     */
    private static function change(array $line, string $change, ?array $old, ?array $new): array
    {
        return [
            'ingredient_id' => $line['ingredient_id'],
            'ingredient' => $line['ingredient_name'],
            'change' => $change,
            'before' => $old !== null ? self::amount($old) : null,
            'after' => $new !== null ? self::amount($new) : null,
            'before_base' => $old !== null ? self::baseAmount($old) : null,
            'after_base' => $new !== null ? self::baseAmount($new) : null,
        ];
    }

    private static function short(string $value): string
    {
        return RecipeQuantity::trim(BigDecimal::of(trim($value) === '' ? '0' : trim($value)));
    }
}
