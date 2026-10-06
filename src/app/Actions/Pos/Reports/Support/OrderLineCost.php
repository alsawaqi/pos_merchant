<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports\Support;

use App\Models\Product;
use App\Support\Catalogue\OrderTypes;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P3 P3-5 — food cost of an order line, COMPLETE: everything the
 * line consumed, read from the copies frozen on the order line at sale time
 * (pos_api CreateOrderHandler / QR OrderLineSnapshotter), never from today's
 * recipes:
 *
 *   recipe_snapshot_json        made-to-order ingredients {ingredient_id, qty,
 *                               unit, unit_cost} per ONE unit (raw lines only:
 *                               pos_api explodes prep items when it copies);
 *   component_snapshot_json     packaging / physical items {product_id, qty};
 *   addon consumption_snapshot  option stock-usage lines: ingredient lines
 *                               (frozen unit_cost) and product lines, add or
 *                               remove — merged with the recipe and the
 *                               components per ingredient / product and
 *                               clamped at 0, exactly like the stock deduction
 *                               (ConsumeInventoryAction): a removal lowers
 *                               cost, never below nothing;
 *   addon ingredient_snapshot   the legacy single-ingredient option;
 *   addon product_snapshot      an add-on that IS a product: its frozen recipe
 *                               (made-to-order) or one piece (cooked / bought-
 *                               in), plus that product's own packaging.
 *
 * Pieces of a product (a cooked dish, a cup, a bought-in can) have no frozen
 * cost on the order line, so a piece costs:
 *
 *   cooked   the batch cost per piece pos_api stamps on the "produced" product
 *            stock movement (unit_cost; LAUNCH-P3 Part B) — the latest batch
 *            finished at the sale's branch at or before the sale, else the
 *            latest at any branch. FALLBACK for sales with no stamped batch
 *            (everything produced before Part B): the product's cost price
 *            when set, else its CURRENT recipe cost (through prep items) — the
 *            same honest-cost rule as product waste.
 *   other    the product's cost price when set, else its current recipe cost
 *            (0 for a bought-in product with no recipe).
 *
 * A line of a cooked or bought-in product carries no recipe copy; its own
 * piece is costed the same way. A made-to-order line with a recipe copy is
 * costed from that copy only (never also as a piece). An untracked product
 * (on its own or as an add-on) consumes nothing: its cost price, when set.
 * A line written before the component copy existed (NULL) uses the product's
 * live components, like the stock deduction does for it.
 *
 * LAUNCH packaging add-on — the copies carry each line's "Used for" ticks
 * (`order_types`, absent = every type). A line is costed with only the lines
 * ticked for the order's type — the type stamped when its stock was taken
 * (`stock_order_type`), else its order type (car = to go) — exactly like the
 * stock deduction. Legacy lines costed from LIVE components are never
 * filtered (old code took every component). The per-order packaging is NOT
 * a line cost: {@see self::packaging()} costs it once per order, for the
 * Sales report only.
 *
 * Amounts are exact decimals, multiplied by the line quantity and rounded
 * ONCE per line to baisas (1 OMR = 1000), like {@see RecipeSnapshotCost}.
 * `recipe` is the line's own recipe / batch part (no options, packaging or
 * add-ons) — what the Recipe & Cost report compares with today's recipe.
 */
final class OrderLineCost
{
    /** @var array<int, object{id: int, stock_mode: string, cost_price: ?string}> */
    private array $products = [];

    /** @var array<int, list<object{branch_id: ?int, occurred_at: string, unit_cost: string}>> product id => produced rows, oldest first */
    private array $batches = [];

    /** @var array<int, BigDecimal> */
    private array $fallbackMemo = [];

    /** @var array<int, list<array{product_id: int, qty: string}>> product id => LIVE components (legacy lines only) */
    private array $liveComponents = [];

    public function __construct(private readonly int $companyId) {}

    /**
     * @param  Collection<int, object>  $items  rows with id, product_id, qty, recipe_snapshot_json, component_snapshot_json, branch_id, sold_at (+ stock_order_type, order_type: the ticks filter)
     * @return array<int, array{total: int, recipe: int}> item id => baisas
     */
    public function costs(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $addons = collect();
        foreach ($items->pluck('id')->chunk(1000) as $chunk) {
            $addons = $addons->concat(DB::table('pos_order_item_addons')
                ->whereIn('order_item_id', $chunk->all())
                ->get(['order_item_id', 'ingredient_snapshot_json', 'consumption_snapshot_json', 'product_snapshot_json']));
        }
        $addonsByItem = $addons->groupBy('order_item_id');

        $this->load($items, $addons);

        $out = [];
        foreach ($items as $item) {
            $out[(int) $item->id] = $this->line($item, $addonsByItem->get($item->id, collect()));
        }

        return $out;
    }

    /**
     * @param  Collection<int, object>  $addons
     * @return array{total: int, recipe: int}
     */
    private function line(object $item, Collection $addons): array
    {
        $qty = self::dec($item->qty);
        $branchId = $item->branch_id !== null ? (int) $item->branch_id : null;
        $soldAt = (string) $item->sold_at;
        // LAUNCH packaging add-on — the order type the stock was taken for.
        $bit = self::typeBit($item);

        $recipe = self::json($item->recipe_snapshot_json);
        $components = self::json($item->component_snapshot_json);

        // ---- per ONE unit: ingredient plan (recipe + option deltas) ----
        $ingredients = [];
        foreach (is_array($recipe) ? $recipe : [] as $line) {
            if (! is_array($line) || ! isset($line['ingredient_id']) || ! OrderTypes::includes($line['order_types'] ?? null, $bit)) {
                continue;
            }
            $id = (int) $line['ingredient_id'];
            $ingredients[$id]['base'] = ($ingredients[$id]['base'] ?? BigDecimal::zero())->plus(self::dec($line['qty'] ?? 0));
            $ingredients[$id]['unit_cost'] = self::dec($line['unit_cost'] ?? 0);
        }
        // A line written before the component copy existed (NULL) is costed
        // with the product's live components — the same fallback the stock
        // deduction (pos_api ConsumeInventoryAction) uses for it.
        // Never filtered by the ticks: old code took every live component.
        $live = false;
        if (! is_array($components) && $item->product_id !== null) {
            $components = $this->liveComponents[(int) $item->product_id] ?? [];
            $live = true;
        }
        $products = [];
        foreach (is_array($components) ? $components : [] as $component) {
            if (! is_array($component) || ! isset($component['product_id'])) {
                continue;
            }
            if (! $live && ! OrderTypes::includes($component['order_types'] ?? null, $bit)) {
                continue;
            }
            $id = (int) $component['product_id'];
            $products[$id]['base'] = ($products[$id]['base'] ?? BigDecimal::zero())->plus(self::dec($component['qty'] ?? 0));
        }

        $extra = BigDecimal::zero(); // per unit: legacy option ingredients + add-on products
        foreach ($addons as $addon) {
            $consumption = self::json($addon->consumption_snapshot_json);
            foreach (is_array($consumption) ? $consumption : [] as $line) {
                if (! is_array($line) || ! OrderTypes::includes($line['order_types'] ?? null, $bit)) {
                    continue;
                }
                $delta = self::dec($line['qty'] ?? 0);
                if (($line['direction'] ?? 'add') === 'remove') {
                    $delta = $delta->negated();
                }
                if (($line['type'] ?? '') === 'ingredient' && isset($line['ingredient_id'])) {
                    $id = (int) $line['ingredient_id'];
                    $ingredients[$id]['delta'] = ($ingredients[$id]['delta'] ?? BigDecimal::zero())->plus($delta);
                    $ingredients[$id]['unit_cost'] ??= self::dec($line['unit_cost'] ?? 0);
                } elseif (($line['type'] ?? '') === 'product' && isset($line['product_id'])) {
                    $id = (int) $line['product_id'];
                    $products[$id]['delta'] = ($products[$id]['delta'] ?? BigDecimal::zero())->plus($delta);
                }
            }

            // Legacy single-ingredient option (only when no consumption lines).
            $legacy = self::json($addon->ingredient_snapshot_json);
            if (! is_array($consumption) && is_array($legacy) && isset($legacy['ingredient_id']) && OrderTypes::includes($legacy['order_types'] ?? null, $bit)) {
                $extra = $extra->plus(self::dec($legacy['qty'] ?? 0)->multipliedBy(self::dec($legacy['unit_cost'] ?? 0)));
            }

            // An add-on that IS a product (one per parent unit).
            $linked = self::json($addon->product_snapshot_json);
            if (is_array($linked) && isset($linked['product_id'])) {
                $mode = (string) ($linked['stock_mode'] ?? '');
                $linkedRecipe = array_values(array_filter(
                    (array) ($linked['recipe'] ?? []),
                    static fn ($line): bool => is_array($line) && OrderTypes::includes($line['order_types'] ?? null, $bit),
                ));
                if ($mode === 'ingredient' && $linkedRecipe !== []) {
                    foreach ($linkedRecipe as $line) {
                        $extra = $extra->plus(self::dec($line['qty'] ?? 0)->multipliedBy(self::dec($line['unit_cost'] ?? 0)));
                    }
                } elseif ($mode === 'cooked' || $mode === 'unit') {
                    $extra = $extra->plus($this->pieceCost((int) $linked['product_id'], $branchId, $soldAt, $mode));
                } else {
                    // Untracked, or made-to-order without a recipe: nothing
                    // left stock — its cost price, when set, like the same
                    // product sold on its own.
                    $extra = $extra->plus($this->costPrice((int) $linked['product_id']));
                }
                foreach ((array) ($linked['components'] ?? []) as $component) {
                    if (is_array($component) && isset($component['product_id']) && OrderTypes::includes($component['order_types'] ?? null, $bit)) {
                        $extra = $extra->plus(self::dec($component['qty'] ?? 0)->multipliedBy(
                            $this->pieceCost((int) $component['product_id'], $branchId, $soldAt),
                        ));
                    }
                }
            }
        }

        // ---- merge, clamp at zero, cost ----
        $perUnit = $extra;
        $recipePerUnit = BigDecimal::zero();
        foreach ($ingredients as $parts) {
            $base = $parts['base'] ?? BigDecimal::zero();
            $total = $base->plus($parts['delta'] ?? BigDecimal::zero());
            if ($total->isNegative()) {
                $total = BigDecimal::zero();
            }
            $unitCost = $parts['unit_cost'] ?? BigDecimal::zero();
            $perUnit = $perUnit->plus($total->multipliedBy($unitCost));
            $recipePerUnit = $recipePerUnit->plus($base->multipliedBy($unitCost));
        }
        foreach ($products as $productId => $parts) {
            $total = ($parts['base'] ?? BigDecimal::zero())->plus($parts['delta'] ?? BigDecimal::zero());
            if ($total->isPositive()) {
                $perUnit = $perUnit->plus($total->multipliedBy($this->pieceCost((int) $productId, $branchId, $soldAt)));
            }
        }

        // The line's own product, when it carries no recipe copy (cooked,
        // bought-in, or untracked): one piece per unit.
        if (! is_array($recipe) && $item->product_id !== null) {
            $own = $this->ownPieceCost((int) $item->product_id, $branchId, $soldAt);
            $perUnit = $perUnit->plus($own);
            $recipePerUnit = $recipePerUnit->plus($own);
        }

        return [
            'total' => self::baisas($perUnit->multipliedBy($qty)),
            'recipe' => self::baisas($recipePerUnit->multipliedBy($qty)),
        ];
    }

    /**
     * LAUNCH packaging add-on — the per-order packaging each order took, from
     * the copy pos_api froze on the order when it took the stock
     * (packaging_snapshot_json: {order_type, lines: [{type: ingredient,
     * ingredient_id, qty, unit, unit_cost} | {type: product, product_id,
     * qty}]}): ingredients at their frozen cost, pieces like any other piece
     * a line consumed. Counted ONCE per order — never on a line, so product
     * performance and recipe cost leave it out. An order with no copy (paid
     * before the release, or no list) took none.
     *
     * @param  Collection<int, object>  $orders  rows with id, branch_id, sold_at, packaging_snapshot_json
     * @return array<int, int> order id => baisas
     */
    public function packaging(Collection $orders): array
    {
        $snapshots = [];
        $ids = [];
        foreach ($orders as $order) {
            $snapshot = self::json($order->packaging_snapshot_json ?? null);
            $lines = is_array($snapshot) && is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
            if ($lines === []) {
                continue;
            }
            $snapshots[(int) $order->id] = [$order, $lines];
            foreach ($lines as $line) {
                if (is_array($line) && ($line['type'] ?? '') === 'product' && isset($line['product_id'])) {
                    $ids[] = (int) $line['product_id'];
                }
            }
        }
        $this->loadProducts($ids);

        $out = [];
        foreach ($snapshots as $orderId => [$order, $lines]) {
            $branchId = $order->branch_id !== null ? (int) $order->branch_id : null;
            $soldAt = (string) $order->sold_at;
            $cost = BigDecimal::zero();
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $qty = self::dec($line['qty'] ?? 0);
                if (! $qty->isPositive()) {
                    continue;
                }
                if (($line['type'] ?? '') === 'ingredient') {
                    $cost = $cost->plus($qty->multipliedBy(self::dec($line['unit_cost'] ?? 0)));
                } elseif (($line['type'] ?? '') === 'product' && isset($line['product_id'])) {
                    $cost = $cost->plus($qty->multipliedBy($this->pieceCost((int) $line['product_id'], $branchId, $soldAt)));
                }
            }
            $out[$orderId] = self::baisas($cost);
        }

        return $out;
    }

    /** The bit of the type the line's stock was taken for (null = no filter). */
    private static function typeBit(object $item): ?int
    {
        $stamped = property_exists($item, 'stock_order_type') ? $item->stock_order_type : null;
        if ($stamped !== null && $stamped !== '') {
            return OrderTypes::bit((string) $stamped);
        }

        return OrderTypes::bit(property_exists($item, 'order_type') ? $item->order_type : null);
    }

    /** A standalone line's own piece: cooked → batch cost; else the cost price (when set). */
    private function ownPieceCost(int $productId, ?int $branchId, string $soldAt): BigDecimal
    {
        $product = $this->products[$productId] ?? null;
        if ($product === null) {
            return BigDecimal::zero();
        }
        // LAUNCH-P4 B8 — a combo line costs nothing itself: its cost is the
        // cost of the items chosen in it (its child lines), never also a
        // cost price set on the combo.
        if (($product->product_type ?? 'standard') === 'combo') {
            return BigDecimal::zero();
        }
        if ($product->stock_mode === 'cooked') {
            return $this->pieceCost($productId, $branchId, $soldAt, 'cooked');
        }

        return $this->costPrice($productId);
    }

    /** The product's cost price when set, else 0. */
    private function costPrice(int $productId): BigDecimal
    {
        $costPrice = self::dec($this->products[$productId]->cost_price ?? 0);

        return $costPrice->isPositive() ? $costPrice : BigDecimal::zero();
    }

    /** One piece of a product consumed by a line (see the class doc). */
    private function pieceCost(int $productId, ?int $branchId, string $soldAt, ?string $frozenMode = null): BigDecimal
    {
        $product = $this->products[$productId] ?? null;
        if ($product === null) {
            return BigDecimal::zero();
        }

        if (($frozenMode ?? $product->stock_mode) === 'cooked') {
            $batch = $this->batchCost($productId, $branchId, $soldAt);
            if ($batch !== null) {
                return $batch;
            }
        }

        return $this->fallbackCost($productId);
    }

    /** The latest stamped batch cost at the branch (else anywhere) at or before the sale. */
    private function batchCost(int $productId, ?int $branchId, string $soldAt): ?BigDecimal
    {
        $atBranch = null;
        $anywhere = null;
        foreach ($this->batches[$productId] ?? [] as $row) {
            if ($row->occurred_at > $soldAt) {
                break;
            }
            $anywhere = $row;
            if ($branchId !== null && (int) $row->branch_id === $branchId) {
                $atBranch = $row;
            }
        }
        $pick = $atBranch ?? $anywhere;

        return $pick === null ? null : self::dec($pick->unit_cost);
    }

    /** Cost price when set, else today's recipe cost (through prep items). */
    private function fallbackCost(int $productId): BigDecimal
    {
        if (isset($this->fallbackMemo[$productId])) {
            return $this->fallbackMemo[$productId];
        }
        $product = $this->products[$productId] ?? null;
        $costPrice = self::dec($product?->cost_price ?? 0);
        if ($costPrice->isPositive()) {
            return $this->fallbackMemo[$productId] = $costPrice;
        }

        $model = Product::withTrashed()->where('company_id', $this->companyId)->find($productId);

        return $this->fallbackMemo[$productId] = $model === null
            ? BigDecimal::zero()
            : self::dec($model->theoreticalCost(perUnitPrecision: true));
    }

    /**
     * @param  Collection<int, object>  $items
     * @param  Collection<int, object>  $addons
     */
    private function load(Collection $items, Collection $addons): void
    {
        $ids = [];
        $legacy = [];
        foreach ($items as $item) {
            if ($item->product_id !== null) {
                $ids[] = (int) $item->product_id;
                if (self::json($item->component_snapshot_json) === null) {
                    $legacy[] = (int) $item->product_id;
                }
            }
            foreach ((array) (self::json($item->component_snapshot_json) ?? []) as $component) {
                if (is_array($component) && isset($component['product_id'])) {
                    $ids[] = (int) $component['product_id'];
                }
            }
        }
        foreach ($addons as $addon) {
            foreach ((array) (self::json($addon->consumption_snapshot_json) ?? []) as $line) {
                if (is_array($line) && isset($line['product_id'])) {
                    $ids[] = (int) $line['product_id'];
                }
            }
            $linked = self::json($addon->product_snapshot_json);
            if (is_array($linked) && isset($linked['product_id'])) {
                $ids[] = (int) $linked['product_id'];
                foreach ((array) ($linked['components'] ?? []) as $component) {
                    if (is_array($component) && isset($component['product_id'])) {
                        $ids[] = (int) $component['product_id'];
                    }
                }
            }
        }
        foreach (array_chunk(array_values(array_unique($legacy)), 1000) as $chunk) {
            foreach (DB::table('pos_product_components')->whereIn('product_id', $chunk)->get(['product_id', 'component_product_id', 'quantity']) as $row) {
                $this->liveComponents[(int) $row->product_id][] = ['product_id' => (int) $row->component_product_id, 'qty' => (string) $row->quantity];
                $ids[] = (int) $row->component_product_id;
            }
        }
        $this->loadProducts($ids);
    }

    /**
     * Products (and the batches of cooked ones) not loaded yet.
     *
     * @param  list<int>  $ids
     */
    private function loadProducts(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->products)));
        if ($ids === []) {
            return;
        }
        $loaded = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('pos_products')->where('company_id', $this->companyId)->whereIn('id', $chunk)->get(['id', 'stock_mode', 'cost_price', 'product_type']) as $row) {
                $this->products[(int) $row->id] = $row;
                $loaded[(int) $row->id] = $row;
            }
        }

        $cooked = array_keys(array_filter($loaded, static fn ($p): bool => $p->stock_mode === 'cooked'));
        foreach (array_chunk($cooked, 1000) as $chunk) {
            $rows = DB::table('pos_product_stock_movements')
                ->where('company_id', $this->companyId)
                ->where('movement_type', 'produced')
                ->whereNotNull('unit_cost')
                ->whereIn('product_id', $chunk)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get(['product_id', 'branch_id', 'occurred_at', 'unit_cost']);
            foreach ($rows as $row) {
                $this->batches[(int) $row->product_id][] = $row;
            }
        }
    }

    /** @return array<mixed>|null */
    private static function json(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function dec(mixed $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');
        }
        $text = trim((string) $value);

        return BigDecimal::of($text === '' || $text === '-' ? '0' : $text);
    }

    private static function baisas(BigDecimal $omr): int
    {
        return $omr->multipliedBy(1000)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }
}
