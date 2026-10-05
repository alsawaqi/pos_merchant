<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * LAUNCH review add-on (A2) — an item's containers ("How do you buy it?").
 *
 * A container is a row of pos_ingredient_units. A LEAF container holds an
 * amount of the item ("bottle holds 1 l", factor 1000 on a ml item); a nested
 * one holds N of exactly one other container of the same item ("crate holds
 * 12 × bottle 1 l", factor 12000). The same word may be used with different
 * sizes, so a container is named by its token ({@see ContainerToken}); a NAME
 * still resolves while exactly one live container has it.
 *
 * Every lookup here is scoped to ONE ingredient, so a token or a name can
 * never reach another item's (or another company's) container.
 */
final class Containers
{
    /** Nesting depth: a carton of crates of bottles is 3 levels. */
    public const MAX_DEPTH = 3;

    /**
     * The ingredient's LIVE containers (the eager-loaded altUnits relation when
     * present, so a list costs no extra queries).
     *
     * @return Collection<int, IngredientAltUnit>
     */
    public static function of(Ingredient $ingredient): Collection
    {
        if ($ingredient->relationLoaded('altUnits')) {
            return $ingredient->altUnits->filter(static fn (IngredientAltUnit $c): bool => ! $c->trashed())->values();
        }

        return IngredientAltUnit::query()
            ->where('ingredient_id', $ingredient->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The container a unit names: a token ("#…"), or a name that exactly one
     * live container has (when several share it, the count container is left
     * out — an old '@piece' count container merged into a row of the same
     * name must not make an older pack name ambiguous). Null when the unit is
     * not a container (a kind unit, '@piece', the stored unit).
     *
     * @throws RuntimeException a token of no live container of this item, or an ambiguous name
     */
    public static function resolve(Ingredient $ingredient, ?string $unit): ?IngredientAltUnit
    {
        $unit = $unit === null ? '' : trim($unit);
        if ($unit === '' || $unit === '@piece' || $unit === $ingredient->unit?->value) {
            return null;
        }

        $all = self::of($ingredient);

        // Fix order B-1 (L9) — only the EXACT token formats are tokens: an
        // older container named "#10 can" still resolves by its name below.
        if (ContainerToken::isToken($unit)) {
            $uuid = ContainerToken::decode($unit);
            $match = $uuid === null ? null : $all->first(static fn (IngredientAltUnit $c): bool => strtolower((string) $c->uuid) === $uuid);
            if ($match === null) {
                throw new RuntimeException('That container is not one of this item\'s containers (it may have been removed).');
            }

            return $match;
        }

        $named = $all->filter(static fn (IngredientAltUnit $c): bool => $c->name === $unit)->values();
        if ($named->count() > 1 && $ingredient->count_container_id !== null) {
            $named = $named->reject(static fn (IngredientAltUnit $c): bool => (int) $c->id === (int) $ingredient->count_container_id)->values();
        }
        if ($named->count() > 1) {
            throw new RuntimeException(sprintf(
                "This item has several containers called '%s' — pick the one you mean (%s).",
                $unit,
                $named->map(fn (IngredientAltUnit $c): string => self::displayName($c, $ingredient, $all))->implode(', '),
            ));
        }

        return $named->first();
    }

    /**
     * A container of this ingredient by uuid (live), or null.
     */
    public static function findByUuid(Ingredient $ingredient, ?string $uuid): ?IngredientAltUnit
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $uuid = strtolower(trim($uuid));
        if (str_starts_with($uuid, ContainerToken::PREFIX)) {
            $uuid = (string) ContainerToken::decode($uuid);
        }

        return self::of($ingredient)->first(static fn (IngredientAltUnit $c): bool => strtolower((string) $c->uuid) === $uuid);
    }

    /**
     * The name people read: a leaf with its size ("bottle 1.5 l"), a nested
     * container with what it holds ("crate (12 × bottle 1 l)").
     *
     * @param  Collection<int, IngredientAltUnit>|null  $all  the item's containers (trashed included is fine)
     */
    public static function displayName(IngredientAltUnit $container, Ingredient $ingredient, ?Collection $all = null, string $locale = 'en', int $depth = 0): string
    {
        $name = $locale === 'ar' && is_string($container->name_ar) && trim($container->name_ar) !== ''
            ? trim($container->name_ar)
            : (string) $container->name;

        if ($container->contains_unit_id === null || $depth >= self::MAX_DEPTH + 1) {
            return trim($name.' '.self::friendly((string) $container->factor, (string) ($ingredient->unit?->value ?? '')));
        }

        $child = self::child($container, $all);
        if ($child === null) {
            return trim($name.' '.self::friendly((string) $container->factor, (string) ($ingredient->unit?->value ?? '')));
        }

        return sprintf('%s (%s × %s)', $name, self::trim((string) $container->contains_quantity), self::displayName($child, $ingredient, $all, $locale, $depth + 1));
    }

    /**
     * The leaf container ONE of $container ends in, and how many of it one
     * holds (crate of 12 bottles → [bottle, 12]; a leaf → [itself, 1]).
     *
     * @param  Collection<int, IngredientAltUnit>|null  $all
     * @return array{0: IngredientAltUnit, 1: BigDecimal}
     */
    public static function leaf(IngredientAltUnit $container, ?Collection $all = null): array
    {
        $pieces = BigDecimal::one();
        $current = $container;
        for ($i = 0; $i <= self::MAX_DEPTH && $current->contains_unit_id !== null; $i++) {
            $child = self::child($current, $all);
            if ($child === null) {
                break;
            }
            $pieces = $pieces->multipliedBy(self::decimal($current->contains_quantity));
            $current = $child;
        }

        return [$current, $pieces];
    }

    /**
     * How deep a container's nesting goes (a leaf = 1, a crate of bottles = 2).
     *
     * @param  Collection<int, IngredientAltUnit>|null  $all
     */
    public static function depth(IngredientAltUnit $container, ?Collection $all = null): int
    {
        $depth = 1;
        $current = $container;
        $seen = [(int) $container->id => true];
        while ($current->contains_unit_id !== null) {
            $child = self::child($current, $all);
            if ($child === null || isset($seen[(int) $child->id])) {
                break;
            }
            $seen[(int) $child->id] = true;
            $depth++;
            $current = $child;
        }

        return $depth;
    }

    /**
     * @param  Collection<int, IngredientAltUnit>|null  $all
     */
    private static function child(IngredientAltUnit $container, ?Collection $all): ?IngredientAltUnit
    {
        if ($container->contains_unit_id === null) {
            return null;
        }
        $child = $all?->first(static fn (IngredientAltUnit $c): bool => (int) $c->id === (int) $container->contains_unit_id);

        return $child ?? IngredientAltUnit::withTrashed()->find($container->contains_unit_id);
    }

    /**
     * An amount as people read it: 1000 g / ml and above in kg / l ("1.5 l",
     * not "1500 ml"); up to 4 decimals, trailing zeros trimmed. Mirrors
     * friendlyAmount in resources/js/lib/itemKind.ts.
     */
    public static function friendly(string $quantity, string $unit): string
    {
        $n = self::decimal($quantity);
        if (($unit === 'g' || $unit === 'ml') && $n->abs()->isGreaterThanOrEqualTo(1000)) {
            return self::trim((string) $n->dividedBy(1000, 4, \Brick\Math\RoundingMode::HALF_UP)).' '.($unit === 'g' ? 'kg' : 'l');
        }

        return self::trim((string) $n->toScale(4, \Brick\Math\RoundingMode::HALF_UP)).' '.$unit;
    }

    /** "12.0000" → "12", "1.5000" → "1.5". */
    public static function trim(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '-0' || $value === '' ? '0' : $value;
    }

    public static function decimal(mixed $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
        }

        return BigDecimal::of(trim((string) $value));
    }
}
