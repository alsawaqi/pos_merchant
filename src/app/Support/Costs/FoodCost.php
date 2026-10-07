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
 *   + components              fix order 1 (K-2): every component that is not
 *                             packaging (a cooked patty, a bought-in sauce
 *                             cup, a general physical item) × its quantity at
 *                             that component's own cost (a cooked product: its
 *                             recipe per piece, else its cost price; a bought
 *                             product: its cost price); with "Used for" ticks,
 *                             the dearest order type
 * Packaging physical items (and per-order packaging) are not food: left out.
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

    /** @var array<int, list<array{0: int, 1: string, 2: int}>> product id => [component product id, quantity, mask] (K-2) */
    private array $components = [];

    /** @var array<int, list<int>>|null raw ingredient id => dishes whose cost moves with it (K-3, built once) */
    private ?array $dishMap = null;

    /** @var array<int, bool> ingredient id => cost complete (per instance) */
    private array $completeMemo = [];

    /** @var array<string, array{cost: BigRational, complete: bool}|null> "product:bit" => own cost (K-9) */
    private array $ownMemo = [];

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
                'status', 'is_internal', 'internal_purpose', 'target_food_cost_percent', 'on_sale_from', 'on_sale_until']) as $p) {
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
        // K-2 — components that are food (not packaging physical items).
        foreach (DB::table('pos_product_components')->whereIn('product_id', $ids ?: [0])->orderBy('id')
            ->get(['product_id', 'component_product_id', 'quantity', 'order_types']) as $line) {
            $component = $self->products[(int) $line->component_product_id] ?? null;
            if ($component === null || self::isPackaging($component)) {
                continue;
            }
            $self->components[(int) $line->product_id][] = [(int) $line->component_product_id, (string) $line->quantity, OrderTypes::read($line->order_types ?? null)];
        }

        $self->meals = DB::table('pos_meals')->where('company_id', $companyId)->whereNull('deleted_at')->where('status', 'active')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'uuid', 'name', 'name_ar', 'meal_price', 'on_sale_from', 'on_sale_until'])->all();
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
        $copy->completeMemo = [];
        $copy->ownMemo = [];

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
            // K-4 — active and on sale today (Asia/Muscat): what the dashboard counts.
            'on_sale' => (string) $p->status === 'active' && self::onSaleToday($p->on_sale_from ?? null, $p->on_sale_until ?? null),
        ] + $this->figures($this->cost($productId), self::baisas($p->base_price), $target ?? $this->companyTarget, $target !== null ? 'product' : 'company');
    }

    /**
     * K-11 — a product's cost as the food cost % uses it (the dearest order
     * type, food components included) and, when it differs by order type,
     * the cost of each type; null with no cost. For the Recipe & Cost report.
     *
     * @return array{cost: BigRational, complete: bool, by_type: array<string, BigRational>|null}|null
     */
    public function breakdown(int $productId): ?array
    {
        return $this->cost($productId);
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
                    // K-16 — the main + the meal lines of ONE order type, the dearest type.
                    $cost = null;
                    if ($mainCost !== null || $lines !== null) {
                        $totals = [];
                        foreach (array_keys(OrderTypes::BUCKETS) as $bucket) {
                            $totals[$bucket] = ($mainCost === null ? BigRational::zero() : self::forType($mainCost, $bucket))
                                ->plus($lines === null ? BigRational::zero() : self::forType($lines, $bucket));
                        }
                        $cost = self::dearest($totals) + ['complete' => $mainCost !== null && $lines !== null && $mainCost['complete'] && $lines['complete']];
                    }
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
                        'on_sale' => self::onSaleToday($meal->on_sale_from ?? null, $meal->on_sale_until ?? null)
                            && self::onSaleToday($main->on_sale_from ?? null, $main->on_sale_until ?? null),
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
        return $this->dishMap()[$ingredientId] ?? [];
    }

    /**
     * Fix order 1 (K-3) — raw ingredient → dishes, built ONCE per instance
     * (every recipe exploded once): the products whose recipe uses it
     * (directly or through prep items), then (K-2) every product using one of
     * those as a food component, any level up, then the combos that can
     * serve one of them. Physical items are never listed.
     *
     * @return array<int, list<int>>
     */
    private function dishMap(): array
    {
        if ($this->dishMap !== null) {
            return $this->dishMap;
        }
        $byIngredient = [];
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
            foreach (array_keys($raw) as $ingredient) {
                $byIngredient[(int) $ingredient][] = (int) $productId;
            }
        }
        $parents = [];
        foreach ($this->components as $productId => $lines) {
            foreach ($lines as [$componentId]) {
                $parents[$componentId][] = (int) $productId;
            }
        }
        $combosOf = [];
        foreach ($this->comboLines as $comboId => $lines) {
            if (! isset($this->products[$comboId])) {
                continue;
            }
            foreach ($lines as $line) {
                foreach ($line->kind === 'fixed' ? [(int) $line->product_id] : $this->choiceItems($line) as $item) {
                    $combosOf[$item][(int) $comboId] = true;
                }
            }
        }
        $map = [];
        foreach ($byIngredient as $ingredient => $direct) {
            $all = array_values(array_unique($direct));
            for ($i = 0; $i < count($all); $i++) {
                foreach ($parents[$all[$i]] ?? [] as $parent) {
                    if (! in_array($parent, $all, true)) {
                        $all[] = $parent;
                    }
                }
            }
            $dishes = [];
            foreach ($all as $productId) {
                if (isset($this->products[$productId]) && ! (bool) $this->products[$productId]->is_internal) {
                    $dishes[$productId] = true;
                }
                foreach (array_keys($combosOf[$productId] ?? []) as $comboId) {
                    $dishes[$comboId] = true;
                }
            }
            $map[$ingredient] = array_keys($dishes);
        }

        return $this->dishMap = $map;
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
        // K-10 — a cost that misses something (an ingredient with "No cost
        // yet", a component or a bought item without a cost price) is
        // 'incomplete', never 'ok': its % is a floor, shown as such.
        $status = $cost === null ? 'no_recipe' : ($net <= 0 ? 'no_price' : ($cost['complete'] ? 'ok' : 'incomplete'));
        $pct = null;
        $over = false;
        $overBy = null;
        if ($status === 'ok' || $status === 'incomplete') {
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

    /**
     * Fix order 2 (K-8, K-9, K-10) — a dish's cost, per ORDER TYPE as stock
     * takes it (pos_api ConsumeInventoryAction / OrderTypes::applies): for
     * each of the four types, its own cost for that type (the recipe lines
     * ticked for it; a cooked dish's batch recipe whatever the ticks; else
     * its cost price) + every food component row ticked for that type × the
     * component's OWN per-piece cost (no components of its own: one level,
     * like stock, so a loop can never recurse). The dish costs its dearest
     * single order type. Complete only when its own cost and every component
     * it uses are known.
     *
     * @return array{cost: BigRational, complete: bool, by_type: array<string, BigRational>|null}|null
     */
    private function cost(int $productId): ?array
    {
        if (array_key_exists($productId, $this->memo)) {
            return $this->memo[$productId];
        }
        $p = $this->products[$productId] ?? null;
        if ($p === null) {
            return $this->memo[$productId] = null;
        }
        if ((string) ($p->product_type ?? 'standard') === 'combo') {
            return $this->memo[$productId] = $this->linesCost($this->comboLines[$productId] ?? []);
        }
        $components = $this->components[$productId] ?? [];
        $hasOwn = false;
        $complete = true;
        $totals = [];
        foreach (OrderTypes::BUCKETS as $bucket => $bit) {
            $own = $this->ownCost($productId, $bit);
            $total = BigRational::zero();
            if ($own !== null) {
                $hasOwn = true;
                $complete = $complete && $own['complete'];
                $total = $own['cost'];
            }
            foreach ($components as [$componentId, $quantity, $mask]) {
                if (! OrderTypes::includes($mask, $bit)) {
                    continue;
                }
                $piece = $this->ownCost($componentId, $bit);
                if ($piece === null) {
                    $complete = false;

                    continue;
                }
                $complete = $complete && $piece['complete'];
                $total = $total->plus($piece['cost']->multipliedBy(BigRational::of($quantity)));
            }
            $totals[$bucket] = $total;
        }
        if (! $hasOwn && $components === []) {
            return $this->memo[$productId] = null;
        }
        // K-10 / fix order 3 (K-13) — a bought or cooked item without its own
        // cost is never complete; a made-to-order dish built only from costed
        // components is.
        if (in_array((string) $p->stock_mode, ['unit', 'cooked'], true)) {
            $complete = $complete && $hasOwn;
        }
        $max = array_reduce($totals, static fn (?BigRational $m, BigRational $c): BigRational => $m === null || $c->isGreaterThan($m) ? $c : $m);
        $same = count(array_unique(array_map(static fn (BigRational $c): string => (string) $c->simplified(), $totals))) === 1;

        return $this->memo[$productId] = ['cost' => $max, 'complete' => $complete, 'by_type' => $same ? null : $totals];
    }

    /**
     * A product's OWN cost for one order type (bit), no components: its
     * recipe (made to order: the lines ticked for that type; cooked: the
     * whole batch recipe per piece), else its cost price; null when it has
     * neither (K-9: a component is costed here, one level only).
     *
     * @return array{cost: BigRational, complete: bool}|null
     */
    private function ownCost(int $productId, int $bit): ?array
    {
        $key = $productId.':'.$bit;
        if (array_key_exists($key, $this->ownMemo)) {
            return $this->ownMemo[$key];
        }
        $p = $this->products[$productId] ?? null;
        if ($p === null || (string) ($p->product_type ?? 'standard') === 'combo') {
            return $this->ownMemo[$key] = null;
        }
        $lines = $this->recipes[$productId] ?? [];
        if ($lines !== []) {
            return $this->ownMemo[$key] = $this->recipeCost((string) $p->stock_mode === 'cooked' ? null : $bit, $lines);
        }
        $price = BigDecimal::of((string) ($p->cost_price ?? '0') ?: '0');

        return $this->ownMemo[$key] = $price->isPositive() ? ['cost' => $price->toBigRational(), 'complete' => true] : null;
    }

    /** A physical item used as packaging (legacy internal rows without a purpose are packaging). */
    private static function isPackaging(object $product): bool
    {
        return (bool) $product->is_internal && ($product->internal_purpose ?? 'packaging') !== 'general';
    }

    /** On sale on the merchant's calendar day (inclusive bounds, NULL = open). */
    private static function onSaleToday(mixed $from, mixed $until): bool
    {
        $today = now('Asia/Muscat')->format('Y-m-d');
        $day = static fn (mixed $value): ?string => $value === null || $value === '' ? null : substr((string) $value, 0, 10);

        return ($day($from) === null || $day($from) <= $today) && ($day($until) === null || $day($until) >= $today);
    }

    /**
     * The recipe lines ticked for one order type (bit), or every line (null:
     * a cooked batch), at today's costs; complete when every ingredient used
     * has a cost.
     *
     * @param  list<array{0: int, 1: string, 2: int}>  $lines
     * @return array{cost: BigRational, complete: bool}
     */
    private function recipeCost(?int $bit, array $lines): array
    {
        $quantities = [];
        foreach ($lines as [$ingredient, $quantity, $mask]) {
            if ($bit !== null && ! OrderTypes::includes($mask, $bit)) {
                continue;
            }
            $quantities[$ingredient] = (string) BigDecimal::of($quantities[$ingredient] ?? '0')->plus($quantity);
        }
        $complete = true;
        try {
            $cost = $this->graph->linesCostExact($quantities);
        } catch (Throwable) {
            $cost = BigRational::zero();
            $complete = false;
        }
        foreach (array_keys($quantities) as $ingredient) {
            if (! ($this->completeMemo[$ingredient] ??= $this->graph->costComplete($ingredient))) {
                $complete = false;
            }
        }

        return ['cost' => $cost, 'complete' => $complete];
    }

    /**
     * Σ of combo / meal lines; null when no line has a costed item.
     *
     * @param  list<object>  $lines
     * @return array{cost: BigRational, complete: bool}|null
     */
    private function linesCost(array $lines): ?array
    {
        // Fix order 3 (K-16) — per ORDER TYPE: each item at its cost for that
        // type (a choice: the cheapest costed item for that type), then the
        // dearest single type, like a dish (K-8).
        $totals = array_fill_keys(array_keys(OrderTypes::BUCKETS), BigRational::zero());
        $known = false;
        $complete = $lines !== [];
        foreach ($lines as $line) {
            if ($line->kind === 'fixed') {
                $items = [$this->cost((int) $line->product_id)];
                $times = (int) ($line->quantity ?? 1);
            } else {
                $items = array_map(fn (int $productId): ?array => $this->cost($productId), $this->choiceItems($line));
                $times = (int) ($line->pick_count ?? 1);
            }
            $items = array_values(array_filter($items, static fn (?array $c): bool => $c !== null));
            if ($items === []) {
                $complete = false;

                continue;
            }
            $known = true;
            foreach (array_keys($totals) as $bucket) {
                $pick = null;
                foreach ($items as $c) {
                    if ($pick === null || self::forType($c, $bucket)->isLessThan(self::forType($pick, $bucket))) {
                        $pick = $c;
                    }
                }
                $complete = $complete && $pick['complete'];
                $totals[$bucket] = $totals[$bucket]->plus(self::forType($pick, $bucket)->multipliedBy($times));
            }
        }

        return $known ? self::dearest($totals) + ['complete' => $complete] : null;
    }

    /**
     * An item's cost for one order type (its single cost when it does not
     * differ by type).
     *
     * @param  array{cost: BigRational, by_type?: array<string, BigRational>|null}  $cost
     */
    private static function forType(array $cost, string $bucket): BigRational
    {
        return $cost['by_type'][$bucket] ?? $cost['cost'];
    }

    /**
     * The dearest of the per-type totals, and the totals when they differ.
     *
     * @param  array<string, BigRational>  $totals
     * @return array{cost: BigRational, by_type: array<string, BigRational>|null}
     */
    private static function dearest(array $totals): array
    {
        $max = array_reduce($totals, static fn (?BigRational $m, BigRational $c): BigRational => $m === null || $c->isGreaterThan($m) ? $c : $m);
        $same = count(array_unique(array_map(static fn (BigRational $c): string => (string) $c->simplified(), $totals))) === 1;

        return ['cost' => $max ?? BigRational::zero(), 'by_type' => $same ? null : $totals];
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
