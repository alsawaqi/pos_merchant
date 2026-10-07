<?php

declare(strict_types=1);

namespace App\Support\Costs;

use App\Models\CompanySetting;
use App\Models\Tax;
use App\Support\Catalogue\OrderTypes;
use App\Support\Recipes\PrepGraph;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * LAUNCH costs & allergens add-on (tester call 2) — a dish's food cost %.
 *
 *   food cost % = the dish's CURRENT cost ÷ its in-store selling price
 *                 EXCLUDING VAT × 100
 *
 * Cost (exact, rounded once to baisas):
 *   a product with a recipe   its recipe at today's ingredient costs (prep
 *                             items costed through their recipes); a made-
 *                             to-order recipe with "Used for" ticks costs as
 *                             its dearest order type — the Recipe & Cost
 *                             report's theoretical cost
 *   a product with no recipe  its cost price when one is set (a bought-in
 *                             item), else it has no cost: "no recipe"
 *   a combo                   Σ its lines: a fixed line × quantity at its
 *                             own product (no upgrade taken: an upgrade only
 *                             ever adds to the price), a choice line × pick
 *                             count at its cheapest offered item
 *   a meal with a main        the main's cost + the meal's lines (as a combo);
 *                             price = the main's price + the meal price
 * Packaging (components, per-order packaging) is not food and is left out.
 *
 * Price excluding VAT (baisas): the in-store base price; when the merchant
 * is VAT-registered and "Menu prices include VAT" is on, minus the VAT
 * inside it, computed exactly like pos_api / mithqal_pricing
 * (round(G × sᵢ ÷ (100 × 10000 + Σs)) per active tax, half away from zero).
 *
 * Target: the product's own target_food_cost_percent, else the company
 * target ({@see CostSettings}). Over target when cost × 100 > target ×
 * price (exact); "over by" is the % minus the target, in points.
 *
 * Statuses: 'ok', 'no_recipe' (no cost), 'no_price' (price 0). `cost_complete`
 * is false while an ingredient has "No cost yet" or a combo line has no
 * costed item (its cost then counts what is known).
 */
final class FoodCost
{
    /** @var array<int, object> product id => row */
    private array $products = [];

    /** @var array<int, list<array{0: int, 1: string, 2: int}>> product id => [ingredient id, quantity, mask] */
    private array $recipes = [];

    /** @var array<int, list<object>> combo product id => lines */
    private array $comboLines = [];

    /** @var array<int, list<object>> meal id => lines */
    private array $mealLines = [];

    /** @var list<object> active meals with categories / excluded */
    private array $meals = [];

    /** @var array<int, list<int>> category id => active standard menu product ids */
    private array $offered = [];

    /** @var array<int, array{cost: BigRational, complete: bool}|null> */
    private array $memo = [];

    private function __construct(
        private PrepGraph $graph,
        private readonly string $companyTarget,
        private readonly bool $inclusive,
        /** @var list<int> */
        private readonly array $scaledRates,
    ) {}

    public static function forCompany(int $companyId): self
    {
        $attributes = request()->attributes;
        $key = 'launch_costs.food_cost.'.$companyId;
        $cost = $attributes->get($key);
        if (! $cost instanceof self) {
            $cost = self::load($companyId);
            $attributes->set($key, $cost);
        }

        return $cost;
    }

    public static function forget(int $companyId): void
    {
        request()->attributes->remove('launch_costs.food_cost.'.$companyId);
    }

    public static function load(int $companyId): self
    {
        $company = DB::table('pos_companies')->where('id', $companyId)->first(['vat_registered_at']);
        $inclusive = $company?->vat_registered_at !== null
            && CompanySetting::boolFor($companyId, CompanySetting::KEY_PRICES_INCLUDE_VAT, true);
        $rates = $inclusive
            ? Tax::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at')
                ->orderBy('sort_order')->orderBy('id')->pluck('rate_percent')
                ->map(static fn ($rate): int => (int) round((float) $rate * 10000))->all()
            : [];

        $self = new self(PrepGraph::load($companyId), CostSettings::target($companyId), $inclusive, $rates);

        foreach (DB::table('pos_products')->where('company_id', $companyId)->whereNull('deleted_at')
            ->get(['id', 'uuid', 'name', 'name_ar', 'category_id', 'base_price', 'cost_price', 'stock_mode', 'product_type',
                'status', 'is_internal', 'target_food_cost_percent']) as $p) {
            $self->products[(int) $p->id] = $p;
            if ((string) $p->status === 'active' && (string) ($p->product_type ?? 'standard') === 'standard'
                && ! (bool) $p->is_internal && $p->category_id !== null) {
                $self->offered[(int) $p->category_id][] = (int) $p->id;
            }
        }
        $ids = array_keys($self->products);
        foreach (DB::table('pos_product_recipes')->whereIn('product_id', $ids ?: [0])->orderBy('sort_order')->orderBy('id')
            ->get(['product_id', 'ingredient_id', 'quantity', 'order_types']) as $line) {
            $self->recipes[(int) $line->product_id][] = [(int) $line->ingredient_id, (string) $line->quantity, OrderTypes::read($line->order_types ?? null)];
        }

        $self->meals = DB::table('pos_meals')->where('company_id', $companyId)->whereNull('deleted_at')->where('status', 'active')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'uuid', 'name', 'name_ar', 'meal_price'])->all();
        $mealIds = array_map(static fn (object $m): int => (int) $m->id, $self->meals);
        $categories = DB::table('pos_meal_categories')->whereIn('meal_id', $mealIds ?: [0])->get(['meal_id', 'category_id'])->groupBy('meal_id');
        $excluded = DB::table('pos_meal_excluded_products')->whereIn('meal_id', $mealIds ?: [0])->get(['meal_id', 'product_id'])->groupBy('meal_id');
        foreach ($self->meals as $meal) {
            $meal->categories = collect($categories->get($meal->id) ?? [])->pluck('category_id')->map(static fn ($id): int => (int) $id)->all();
            $meal->excluded = collect($excluded->get($meal->id) ?? [])->pluck('product_id')->map(static fn ($id): int => (int) $id)->all();
        }

        $lines = DB::table('pos_combo_lines')->where('company_id', $companyId)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'combo_product_id', 'meal_id', 'kind', 'product_id', 'quantity', 'category_id', 'pick_count']);
        $lineIds = $lines->pluck('id')->all();
        $out = DB::table('pos_combo_line_items')->whereIn('line_id', $lineIds ?: [0])->where('excluded', true)->get(['line_id', 'product_id'])->groupBy('line_id');
        foreach ($lines as $line) {
            $line->excluded = collect($out->get($line->id) ?? [])->pluck('product_id')->map(static fn ($id): int => (int) $id)->all();
            if ($line->combo_product_id !== null) {
                $self->comboLines[(int) $line->combo_product_id][] = $line;
            } elseif ($line->meal_id !== null) {
                $self->mealLines[(int) $line->meal_id][] = $line;
            }
        }

        return $self;
    }

    /** The same catalogue with one raw ingredient at another price per base unit. */
    public function withIngredientCost(int $ingredientId, string $unitCost): self
    {
        $copy = clone $this;
        $copy->graph = $this->graph->withUnitCost($ingredientId, $unitCost);
        $copy->memo = [];

        return $copy;
    }

    /** @return array<string, mixed>|null the dish's row; null for an unknown, deleted or physical item */
    public function product(int $productId): ?array
    {
        $p = $this->products[$productId] ?? null;
        if ($p === null || (bool) $p->is_internal) {
            return null;
        }
        $target = CostSettings::percent($p->target_food_cost_percent ?? null);

        return [
            'type' => 'product',
            'product_id' => (int) $p->id,
            'product_uuid' => (string) $p->uuid,
            'name' => (string) $p->name,
            'name_ar' => $p->name_ar,
            'product_type' => (string) ($p->product_type ?? 'standard'),
            'product_status' => (string) $p->status,
        ] + $this->figures($this->cost($productId), self::baisas($p->base_price), $target ?? $this->companyTarget, $target !== null ? 'product' : 'company');
    }

    /** @return list<array<string, mixed>> every menu product (not a physical item), in name order */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->products as $id => $p) {
            $row = $this->product((int) $id);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: $a['product_id'] <=> $b['product_id']);

        return $rows;
    }

    /**
     * Every active meal with each of its mains ("Beef burger meal"):
     * price = the main's price + the meal price, cost = the main's + the
     * meal lines', target = the company's.
     *
     * @return list<array<string, mixed>>
     */
    public function mealRows(): array
    {
        $rows = [];
        foreach ($this->meals as $meal) {
            $lines = $this->linesCost($this->mealLines[(int) $meal->id] ?? []);
            foreach ($meal->categories as $categoryId) {
                foreach ($this->offered[$categoryId] ?? [] as $mainId) {
                    if (in_array($mainId, $meal->excluded, true)) {
                        continue;
                    }
                    $main = $this->products[$mainId];
                    $mainCost = $this->cost($mainId);
                    $cost = $mainCost === null && $lines === null ? null : [
                        'cost' => ($mainCost['cost'] ?? BigRational::zero())->plus($lines['cost'] ?? BigRational::zero()),
                        'complete' => $mainCost !== null && $lines !== null && $mainCost['complete'] && $lines['complete'],
                    ];
                    $rows[] = [
                        'type' => 'meal',
                        'meal_id' => (int) $meal->id,
                        'meal_uuid' => (string) $meal->uuid,
                        'product_id' => $mainId,
                        'product_uuid' => (string) $main->uuid,
                        'name' => trim($main->name.' '.$meal->name),
                        'name_ar' => $main->name_ar !== null || $meal->name_ar !== null
                            ? trim(($main->name_ar ?? $main->name).' '.($meal->name_ar ?? $meal->name)) : null,
                        'product_type' => 'meal',
                        'product_status' => 'active',
                    ] + $this->figures($cost, self::baisas($main->base_price) + self::baisas($meal->meal_price), $this->companyTarget, 'company');
                }
            }
        }

        return $rows;
    }

    /**
     * The dishes whose cost moves with this raw ingredient: products whose
     * recipe uses it (directly or through prep items) and the combos that
     * can serve one of them.
     *
     * @return list<int>
     */
    public function dishesUsing(int $ingredientId): array
    {
        $direct = [];
        foreach ($this->recipes as $productId => $lines) {
            $quantities = [];
            foreach ($lines as [$ingredient, $quantity]) {
                $quantities[$ingredient] = (string) BigDecimal::of($quantities[$ingredient] ?? '0')->plus($quantity);
            }
            try {
                $raw = $this->graph->explodeExact($quantities);
            } catch (Throwable) {
                continue;
            }
            if (isset($raw[$ingredientId]) && isset($this->products[$productId]) && ! (bool) $this->products[$productId]->is_internal) {
                $direct[] = (int) $productId;
            }
        }
        $combos = [];
        foreach ($this->comboLines as $comboId => $lines) {
            if (! isset($this->products[$comboId])) {
                continue;
            }
            foreach ($lines as $line) {
                $items = $line->kind === 'fixed' ? [(int) $line->product_id] : $this->choiceItems($line);
                if (array_intersect($items, $direct) !== []) {
                    $combos[] = (int) $comboId;
                    break;
                }
            }
        }

        return array_values(array_unique(array_merge($direct, $combos)));
    }

    /**
     * @param  array{cost: BigRational, complete: bool}|null  $cost
     * @return array<string, mixed>
     */
    private function figures(?array $cost, int $price, string $target, string $targetSource): array
    {
        $net = $this->net($price);
        $costBaisas = $cost === null ? null
            : $cost['cost']->multipliedBy(1000)->toScale(0, RoundingMode::HALF_UP)->toInt();
        $status = $cost === null ? 'no_recipe' : ($net <= 0 ? 'no_price' : 'ok');
        $pct = null;
        $over = false;
        $overBy = null;
        if ($status === 'ok') {
            $pct = (float) (string) BigDecimal::of($costBaisas)->multipliedBy(100)->dividedBy($net, 1, RoundingMode::HALF_UP);
            // Exact: cost × 100 > target × price (target in hundredths).
            $over = BigDecimal::of($costBaisas)->multipliedBy(10000)->isGreaterThan(BigDecimal::of($target)->multipliedBy(100)->multipliedBy($net));
            $overBy = $over ? (float) (string) BigDecimal::of((string) $pct)->minus($target)->toScale(1, RoundingMode::HALF_UP) : null;
        }

        return [
            'status' => $status,
            'cost_baisas' => $costBaisas,
            'cost_complete' => $cost !== null && $cost['complete'],
            'price_baisas' => $price,
            'net_price_baisas' => $net,
            'food_cost_pct' => $pct,
            'target_pct' => (float) $target,
            'target_source' => $targetSource,
            'over_target' => $over,
            'over_by_pct' => $overBy,
        ];
    }

    /** @return array{cost: BigRational, complete: bool}|null */
    private function cost(int $productId): ?array
    {
        if (array_key_exists($productId, $this->memo)) {
            return $this->memo[$productId];
        }
        $this->memo[$productId] = null; // a component loop costs nothing twice
        $p = $this->products[$productId] ?? null;
        if ($p === null) {
            return null;
        }
        if ((string) ($p->product_type ?? 'standard') === 'combo') {
            return $this->memo[$productId] = $this->linesCost($this->comboLines[$productId] ?? []);
        }
        $lines = $this->recipes[$productId] ?? [];
        if ($lines !== []) {
            return $this->memo[$productId] = $this->recipeCost((string) $p->stock_mode, $lines);
        }
        $price = BigDecimal::of((string) ($p->cost_price ?? '0') ?: '0');

        return $this->memo[$productId] = $price->isPositive() ? ['cost' => $price->toBigRational(), 'complete' => true] : null;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $lines
     * @return array{cost: BigRational, complete: bool}
     */
    private function recipeCost(string $stockMode, array $lines): array
    {
        $perType = $stockMode === 'ingredient' && array_filter($lines, static fn (array $l): bool => $l[2] !== OrderTypes::ALL) !== [];
        $max = null;
        $complete = true;
        foreach ($perType ? array_values(OrderTypes::BUCKETS) : [null] as $bit) {
            $quantities = [];
            foreach ($lines as [$ingredient, $quantity, $mask]) {
                if ($bit !== null && ! OrderTypes::includes($mask, $bit)) {
                    continue;
                }
                $quantities[$ingredient] = (string) BigDecimal::of($quantities[$ingredient] ?? '0')->plus($quantity);
            }
            try {
                $cost = $this->graph->linesCostExact($quantities);
            } catch (Throwable) {
                $cost = BigRational::zero();
                $complete = false;
            }
            if ($max === null || $cost->isGreaterThan($max)) {
                $max = $cost;
            }
        }
        foreach ($lines as [$ingredient]) {
            if (! $this->graph->costComplete($ingredient)) {
                $complete = false;
            }
        }

        return ['cost' => $max ?? BigRational::zero(), 'complete' => $complete];
    }

    /**
     * Σ of combo / meal lines; null when no line has a costed item.
     *
     * @param  list<object>  $lines
     * @return array{cost: BigRational, complete: bool}|null
     */
    private function linesCost(array $lines): ?array
    {
        $total = BigRational::zero();
        $known = false;
        $complete = $lines !== [];
        foreach ($lines as $line) {
            if ($line->kind === 'fixed') {
                $item = $this->cost((int) $line->product_id);
                $times = (int) ($line->quantity ?? 1);
            } else {
                $item = null;
                foreach ($this->choiceItems($line) as $productId) {
                    $c = $this->cost($productId);
                    if ($c !== null && ($item === null || $c['cost']->isLessThan($item['cost']))) {
                        $item = $c;
                    }
                }
                $times = (int) ($line->pick_count ?? 1);
            }
            if ($item === null) {
                $complete = false;

                continue;
            }
            $known = true;
            $complete = $complete && $item['complete'];
            $total = $total->plus($item['cost']->multipliedBy($times));
        }

        return $known ? ['cost' => $total, 'complete' => $complete] : null;
    }

    /** @return list<int> the products a choice line offers (its category's menu products minus the unticked ones) */
    private function choiceItems(object $line): array
    {
        if ($line->kind !== 'choice' || $line->category_id === null) {
            return [];
        }

        return array_values(array_diff($this->offered[(int) $line->category_id] ?? [], $line->excluded));
    }

    /** The price excluding VAT (baisas). */
    private function net(int $gross): int
    {
        if (! $this->inclusive || $this->scaledRates === [] || $gross <= 0) {
            return $gross;
        }
        $denominator = 100 * 10000 + array_sum($this->scaledRates);
        $tax = 0;
        foreach ($this->scaledRates as $rate) {
            $tax += intdiv(2 * $gross * $rate + $denominator, 2 * $denominator);
        }

        return $gross - $tax;
    }

    private static function baisas(mixed $value): int
    {
        return BigDecimal::of((string) ($value ?? '0') ?: '0')->multipliedBy(1000)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }
}
