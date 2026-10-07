<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P3 P3-4 — the ONE place prep items are exploded and costed.
 *
 * A prep item P (a sauce, a dough) has a recipe per batch and a yield
 * (prep_yield_quantity, in P's own base unit). The data contract with
 * pos_api fixes the rule:
 *
 *   EXPLODE  a line using P with quantity q (P's base units) becomes, for
 *            each component c of P, a line of q × c.quantity ÷ P.yield of c;
 *            repeated until only raw ingredients remain, and lines of the
 *            same raw ingredient are merged.
 *   COST     the cost of P per base unit is Σ(c.quantity × cost(c)) ÷ P.yield,
 *            recursively; a raw ingredient costs its default_unit_cost
 *            (the weighted average per base unit).
 *
 * All arithmetic is exact (rationals); quantities are rounded to 4 decimals
 * and costs to 6 only at the end, never in between.
 *
 * Validation (used when a prep recipe is saved): a component may itself be a
 * prep item up to {@see MAX_DEPTH} levels deep, and cycles are refused. The
 * same-company rule is enforced where uuids are resolved (every lookup is
 * company-scoped) and the graph is always built for ONE company.
 */
final class PrepGraph
{
    /** Prep levels allowed: P (raw only) = 1, a prep using P = 2, using that = 3. */
    public const MAX_DEPTH = 3;

    /** @var array<int, BigRational> */
    private array $costMemo = [];

    /**
     * @param  array<int, array{yield: string|null, lines: array<int, string>}>  $prep  prep id => yield + [component id => quantity per batch]
     * @param  array<int, string>  $costs  ingredient id => default_unit_cost (raw ingredients; prep entries ignored)
     * @param  array<int, string>  $names  ingredient id => name (messages only)
     * @param  list<int>  $deleted  soft-deleted prep items: still exploded and costed (an old
     *                              recipe version may name one) but never validated as a root
     * @param  array<int, string>  $units  ingredient id => base unit (messages only)
     */
    public function __construct(
        private array $prep,
        private readonly array $costs = [],
        private readonly array $names = [],
        private readonly array $deleted = [],
        private readonly array $units = [],
    ) {}

    /**
     * The graph of one company, memoised for the current request (the product
     * list costs every row from one graph). Writers call {@see forget()} after
     * changing a prep recipe or an ingredient cost, and build their own fresh
     * graph with {@see load()} for validation.
     */
    public static function forCompany(int $companyId): self
    {
        $attributes = request()->attributes;
        $key = 'launch_p3.prep_graph.'.$companyId;
        $graph = $attributes->get($key);
        if (! $graph instanceof self) {
            $graph = self::load($companyId);
            $attributes->set($key, $graph);
        }

        return $graph;
    }

    public static function forget(int $companyId): void
    {
        request()->attributes->remove('launch_p3.prep_graph.'.$companyId);
    }

    /** A fresh graph of one company (soft-deleted rows included: old recipes keep their meaning). */
    public static function load(int $companyId): self
    {
        $rows = DB::table('pos_ingredients')
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'unit', 'default_unit_cost', 'is_prep', 'prep_yield_quantity', 'deleted_at']);

        $prep = [];
        $costs = [];
        $names = [];
        $deleted = [];
        $units = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $names[$id] = (string) $row->name;
            $units[$id] = (string) $row->unit;
            if ((bool) $row->is_prep) {
                if ($row->deleted_at !== null) {
                    $deleted[] = $id;
                }
                $prep[$id] = [
                    'yield' => $row->prep_yield_quantity !== null ? (string) $row->prep_yield_quantity : null,
                    'lines' => [],
                ];
            } else {
                $costs[$id] = (string) ($row->default_unit_cost ?? '0');
            }
        }

        if ($prep !== []) {
            $lines = DB::table('pos_ingredient_recipes')
                ->whereIn('prep_ingredient_id', array_keys($prep))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['prep_ingredient_id', 'ingredient_id', 'quantity']);
            foreach ($lines as $line) {
                $prep[(int) $line->prep_ingredient_id]['lines'][(int) $line->ingredient_id] = (string) $line->quantity;
            }
        }

        return new self($prep, $costs, $names, $deleted, $units);
    }

    public function isPrep(int $ingredientId): bool
    {
        return isset($this->prep[$ingredientId]);
    }

    /** @return array<int, string> component id => quantity per batch */
    public function components(int $prepId): array
    {
        return $this->prep[$prepId]['lines'] ?? [];
    }

    public function yield(int $prepId): ?string
    {
        return $this->prep[$prepId]['yield'] ?? null;
    }

    public function name(int $ingredientId): string
    {
        return $this->names[$ingredientId] ?? ('#'.$ingredientId);
    }

    /** The base unit of an ingredient ('' when unknown). */
    public function unit(int $ingredientId): string
    {
        return $this->units[$ingredientId] ?? '';
    }

    /**
     * The graph with one prep recipe replaced — what saving it WOULD give,
     * for {@see assertValid()} before anything is written.
     *
     * @param  array<int, string|int|float>  $lines  component id => quantity per batch
     */
    public function withRecipe(int $prepId, string|int|float|null $yield, array $lines, ?string $name = null): self
    {
        $prep = $this->prep;
        $prep[$prepId] = [
            'yield' => $yield === null ? null : (string) $yield,
            'lines' => array_map(static fn ($q): string => (string) $q, $lines),
        ];
        $names = $this->names;
        if ($name !== null) {
            $names[$prepId] = $name;
        }

        return new self($prep, $this->costs, $names, $this->deleted, $this->units);
    }

    /**
     * LAUNCH costs & allergens add-on — the graph with one RAW ingredient
     * costed at $unitCost per base unit (a price-change alert's "at the old
     * price" / "at the new price"); every prep item costs through it.
     */
    public function withUnitCost(int $ingredientId, string $unitCost): self
    {
        $costs = $this->costs;
        $costs[$ingredientId] = $unitCost;

        return new self($this->prep, $costs, $this->names, $this->deleted, $this->units);
    }

    /**
     * Explode recipe lines into raw ingredients, merged.
     *
     * @param  array<int, string|int|float|BigNumber>  $lines  ingredient id => base quantity (a prep id is exploded)
     * @return array<int, string> raw ingredient id => quantity (4 decimals, StockDecimal format); lines that round to 0 are dropped
     */
    public function explode(array $lines): array
    {
        /** @var array<int, BigRational> $raw */
        $raw = [];
        foreach ($lines as $ingredientId => $quantity) {
            $this->collect((int) $ingredientId, self::rational($quantity), $raw, []);
        }

        $out = [];
        foreach ($raw as $id => $quantity) {
            $rounded = $quantity->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
            if ($rounded->isZero()) {
                continue;
            }
            $out[$id] = (string) StockDecimal::quantity((string) $rounded);
        }

        return $out;
    }

    /**
     * Explode recipe lines into raw ingredients, merged and EXACT (no
     * rounding) — what one unit really uses, before a copy rounds it.
     *
     * @param  array<int, string|int|float|BigNumber>  $lines  ingredient id => base quantity (a prep id is exploded)
     * @return array<int, BigRational> raw ingredient id => exact quantity
     */
    public function explodeExact(array $lines): array
    {
        /** @var array<int, BigRational> $raw */
        $raw = [];
        foreach ($lines as $ingredientId => $quantity) {
            $this->collect((int) $ingredientId, self::rational($quantity), $raw, []);
        }

        return $raw;
    }

    /** Exact cost of ONE base unit (raw: the weighted average; prep: its recipe ÷ yield). */
    public function unitCostExact(int $ingredientId): BigRational
    {
        return $this->cost($ingredientId, []);
    }

    /** Cost of one base unit, 6 decimals. */
    public function unitCost(int $ingredientId): string
    {
        return (string) StockDecimal::unitCost((string) $this->unitCostExact($ingredientId)->toScale(StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP));
    }

    /**
     * LAUNCH review add-on (A1) — whether the cost is known: a raw ingredient
     * once a priced purchase set its weighted average (> 0, "No cost yet"
     * before); a prep item once every raw ingredient it explodes into has one.
     */
    public function costComplete(int $ingredientId): bool
    {
        try {
            $raw = $this->explodeExact([$ingredientId => '1']);
        } catch (\Throwable) {
            return false;
        }
        if ($raw === []) {
            return false;
        }
        foreach (array_keys($raw) as $id) {
            if (! self::rational($this->costs[(int) $id] ?? '0')->isPositive()) {
                return false;
            }
        }

        return true;
    }

    /** Exact cost of ONE batch of a prep item (Σ component quantity × component cost). */
    public function batchCostExact(int $prepId): BigRational
    {
        $total = BigRational::zero();
        foreach ($this->components($prepId) as $componentId => $quantity) {
            $total = $total->plus(self::rational($quantity)->multipliedBy($this->cost((int) $componentId, [$prepId])));
        }

        return $total;
    }

    /**
     * Exact cost of a set of recipe lines (each quantity × its unit cost,
     * prep items costed through their recipes).
     *
     * @param  array<int, string|int|float|BigNumber>  $lines  ingredient id => base quantity
     */
    public function linesCostExact(array $lines): BigRational
    {
        $total = BigRational::zero();
        foreach ($lines as $ingredientId => $quantity) {
            $total = $total->plus(self::rational($quantity)->multipliedBy($this->unitCostExact((int) $ingredientId)));
        }

        return $total;
    }

    /** Prep levels under an ingredient: 0 for a raw ingredient, 1 + the deepest prep component otherwise. */
    public function depth(int $ingredientId): int
    {
        return $this->depthOf($ingredientId, []);
    }

    /**
     * Refuse cycles and nesting deeper than {@see MAX_DEPTH} anywhere in the
     * graph (an edit to P can deepen every prep item that uses P).
     *
     * @param  int|null  $startAt  the prep item being saved: checked first, so a
     *                             loop is reported from its point of view
     *
     * @throws PrepRecipeException
     */
    public function assertValid(?int $startAt = null): void
    {
        if ($startAt !== null && $this->isPrep($startAt)) {
            $this->depthOf($startAt, []);
        }
        // Only LIVE prep items are roots: a deleted one (hidden, not editable)
        // must never block an edit of a live item it once used.
        $live = array_values(array_diff(array_keys($this->prep), $this->deleted));
        foreach ($live as $prepId) {
            $this->depthOf($prepId, []);
        }
        foreach ($live as $prepId) {
            $depth = $this->depthOf($prepId, []);
            if ($depth > self::MAX_DEPTH) {
                throw new PrepRecipeException(sprintf(
                    'Prep items can be nested at most %d levels deep: %s would be %d.',
                    self::MAX_DEPTH,
                    implode(' → ', array_map(fn (int $id): string => $this->name($id), $this->deepestChain($prepId))),
                    $depth,
                ));
            }
        }
    }

    /** Where-used inside the graph: prep items whose recipe lists this ingredient. @return list<int> */
    public function prepItemsUsing(int $ingredientId): array
    {
        $out = [];
        foreach ($this->prep as $prepId => $entry) {
            if (array_key_exists($ingredientId, $entry['lines'])) {
                $out[] = (int) $prepId;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, BigRational>  $raw
     * @param  list<int>  $path
     */
    private function collect(int $ingredientId, BigRational $quantity, array &$raw, array $path): void
    {
        if (! $this->isPrep($ingredientId)) {
            $raw[$ingredientId] = isset($raw[$ingredientId]) ? $raw[$ingredientId]->plus($quantity) : $quantity;

            return;
        }

        $this->guardCycle($ingredientId, $path);
        $yield = $this->positiveYield($ingredientId);
        $path[] = $ingredientId;
        foreach ($this->components($ingredientId) as $componentId => $perBatch) {
            $this->collect((int) $componentId, $quantity->multipliedBy(self::rational($perBatch))->dividedBy($yield), $raw, $path);
        }
    }

    /** @param list<int> $path */
    private function cost(int $ingredientId, array $path): BigRational
    {
        if (isset($this->costMemo[$ingredientId])) {
            return $this->costMemo[$ingredientId];
        }

        if (! $this->isPrep($ingredientId)) {
            return $this->costMemo[$ingredientId] = self::rational($this->costs[$ingredientId] ?? '0');
        }

        $this->guardCycle($ingredientId, $path);
        $yield = $this->positiveYield($ingredientId);
        $path[] = $ingredientId;
        $batch = BigRational::zero();
        foreach ($this->components($ingredientId) as $componentId => $perBatch) {
            $batch = $batch->plus(self::rational($perBatch)->multipliedBy($this->cost((int) $componentId, $path)));
        }

        return $this->costMemo[$ingredientId] = $batch->dividedBy($yield);
    }

    /** @param list<int> $path */
    private function depthOf(int $ingredientId, array $path): int
    {
        if (! $this->isPrep($ingredientId)) {
            return 0;
        }
        $this->guardCycle($ingredientId, $path);
        $path[] = $ingredientId;
        $deepest = 0;
        foreach (array_keys($this->components($ingredientId)) as $componentId) {
            $deepest = max($deepest, $this->depthOf((int) $componentId, $path));
        }

        return 1 + $deepest;
    }

    /** @return list<int> the longest prep chain starting at $prepId */
    private function deepestChain(int $prepId): array
    {
        $best = [];
        foreach (array_keys($this->components($prepId)) as $componentId) {
            if ($this->isPrep((int) $componentId)) {
                $chain = $this->deepestChain((int) $componentId);
                if (count($chain) > count($best)) {
                    $best = $chain;
                }
            }
        }

        return [$prepId, ...$best];
    }

    /** @param list<int> $path */
    private function guardCycle(int $ingredientId, array $path): void
    {
        $at = array_search($ingredientId, $path, true);
        if ($at === false) {
            return;
        }
        $loop = [...array_slice($path, (int) $at), $ingredientId];

        throw new PrepRecipeException(sprintf(
            'A prep item cannot use itself, directly or through another prep item: %s.',
            implode(' → ', array_map(fn (int $id): string => $this->name($id), $loop)),
        ));
    }

    private function positiveYield(int $prepId): BigRational
    {
        $yield = $this->yield($prepId);
        $value = $yield === null ? BigRational::zero() : self::rational($yield);
        if (! $value->isPositive()) {
            throw new PrepRecipeException(sprintf('Prep item "%s" has no yield: set what one batch makes.', $this->name($prepId)));
        }

        return $value;
    }

    private static function rational(string|int|float|BigNumber $value): BigRational
    {
        if ($value instanceof BigNumber) {
            return $value->toBigRational();
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        return BigRational::of(trim((string) $value) === '' ? '0' : trim((string) $value));
    }

    /** Round an exact amount to money (3 decimals, half up). */
    public static function money(BigRational $amount): BigDecimal
    {
        return $amount->toScale(3, RoundingMode::HALF_UP);
    }
}
