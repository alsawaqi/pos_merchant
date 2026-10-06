<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Support\Catalogue\OrderTypes;
use App\Support\StockDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use RuntimeException;

/**
 * LAUNCH-P3 fix order 1, M1-a — the save-time precision guard for prep items.
 *
 * An order line copies its recipe per ONE unit sold, exploded into raw
 * ingredients and rounded to the ledger's 4 decimals of each raw ingredient's
 * base unit (pos_api RecipeCopy / PrepExploder). Every typed line already
 * passes the P3-1 rule ({@see RecipeQuantity}: nothing that rounds to 0,
 * nothing that moves more than 1 %), but a prep item divides by its yield:
 * 15 ml of a 1000 ml saffron syrup made with 2 g of a kg-based saffron is
 * 0.00003 kg per latte — 0 at 4 decimals, so the sale would never deduct it.
 *
 * So a product recipe, an add-on option's stock-usage lines and every user of
 * an edited prep item are exploded per ONE unit, exactly, and each raw line
 * must pass the same zero / 1 % rule. The refusal names the raw ingredient
 * and tells the merchant to keep it in g or ml (a used ingredient's base unit
 * is locked, so that means a g / ml ingredient).
 */
final class ExplodedPrecision
{
    /**
     * @param  array<int, string|int|float>  $lines  ingredient id => base quantity per ONE unit sold
     * @param  string  $usedBy  what one unit is, for the message ('"Latte"', '"Extra shot" option')
     *
     * @throws RuntimeException when a raw line cannot be recorded accurately
     */
    public static function assertRecordable(PrepGraph $graph, array $lines, string $usedBy): void
    {
        // Only lines that go through a prep item can create a new amount: a
        // typed raw line is already a stored 4-decimal amount.
        $preps = array_values(array_filter(array_keys($lines), static fn ($id): bool => $graph->isPrep((int) $id)));
        if ($preps === []) {
            return;
        }

        /** @var array<int, list<int>> $via raw ingredient id => prep items it comes through */
        $via = [];
        foreach ($preps as $prepId) {
            foreach (array_keys($graph->explodeExact([(int) $prepId => $lines[$prepId]])) as $rawId) {
                $via[$rawId][] = (int) $prepId;
            }
        }

        foreach ($graph->explodeExact($lines) as $rawId => $exact) {
            if ($exact->isZero() || ! isset($via[$rawId])) {
                continue;
            }
            $stored = $exact->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
            $tooSmall = $stored->isZero();
            $tooImprecise = ! $tooSmall
                && $stored->toBigRational()->minus($exact)->abs()->multipliedBy(100)->isGreaterThan($exact->abs());
            if (! $tooSmall && ! $tooImprecise) {
                continue;
            }

            $name = $graph->name((int) $rawId);
            $unit = $graph->unit((int) $rawId);
            $through = implode(', ', array_map(static fn (int $id): string => $graph->name($id), array_unique($via[$rawId])));

            throw new RuntimeException(sprintf(
                $tooSmall
                    ? '%1$s: each %2$s uses %3$s %4$s of it through %5$s — too small to record: stock is kept in %4$s to 4 decimals, so it would become 0 %4$s and never be deducted. Keep %1$s in %7$s (a smaller base unit), or use more of it per batch.'
                    : '%1$s: each %2$s uses %3$s %4$s of it through %5$s, which cannot be recorded accurately — stock is kept in %4$s to 4 decimals, so it would become %6$s %4$s. Keep %1$s in %7$s (a smaller base unit), or use more of it per batch.',
                $name,
                $usedBy,
                self::show($exact),
                $unit,
                $through,
                RecipeQuantity::trim($stored),
                self::smallerUnit($unit),
            ));
        }
    }

    /**
     * After a prep recipe or yield change: every product recipe and add-on
     * option that uses the prep item — directly or through other prep items —
     * must still record accurately with the new explosion.
     *
     * @throws RuntimeException naming the first product or option that would not
     */
    public static function assertUsersRecordable(PrepGraph $graph, int $prepId): void
    {
        $affected = [$prepId];
        for ($i = 0; $i < count($affected); $i++) {
            foreach ($graph->prepItemsUsing($affected[$i]) as $parent) {
                if (! in_array($parent, $affected, true)) {
                    $affected[] = $parent;
                }
            }
        }

        $productIds = ProductRecipe::query()->whereIn('ingredient_id', $affected)->pluck('product_id')->unique()->all();
        $products = Product::query()->whereIn('id', $productIds ?: [0])->orderBy('name')->get(['id', 'name']);
        $recipes = ProductRecipe::query()->whereIn('product_id', $products->pluck('id')->all() ?: [0])->get(['product_id', 'ingredient_id', 'quantity', 'order_types'])->groupBy('product_id');
        foreach ($products as $product) {
            // LAUNCH packaging add-on — lines of one ingredient differ by
            // their ticks; pos_api explodes each tick set as its own group.
            $byTypes = [];
            foreach ($recipes->get($product->id, collect()) as $line) {
                $byTypes[OrderTypes::read($line->order_types)][(int) $line->ingredient_id] = (string) $line->quantity;
            }
            foreach ($byTypes as $lines) {
                self::assertRecordable($graph, $lines, '"'.$product->name.'"');
            }
        }

        $addonIds = AddOnConsumption::query()->whereIn('ingredient_id', $affected)->pluck('add_on_id')->unique()->all();
        $addons = AddOn::query()->whereIn('id', $addonIds ?: [0])->orderBy('name')->get(['id', 'name']);
        $consumption = AddOnConsumption::query()->whereIn('add_on_id', $addons->pluck('id')->all() ?: [0])->whereNotNull('ingredient_id')
            ->get(['add_on_id', 'ingredient_id', 'direction', 'quantity', 'order_types'])->groupBy('add_on_id');
        foreach ($addons as $addon) {
            self::assertOptionRecordable($graph, $consumption->get($addon->id, collect())->map(static fn (AddOnConsumption $l): array => [
                'ingredient_id' => (int) $l->ingredient_id,
                'direction' => (string) $l->direction,
                'quantity' => (string) $l->quantity,
                'order_types' => OrderTypes::read($l->order_types),
            ])->all(), (string) $addon->name);
        }
    }

    /**
     * An option's ingredient lines merge per direction (pos_api keeps "add"
     * and "remove" apart), so each direction is checked on its own.
     *
     * LAUNCH packaging add-on — and each tick set on its own (the merge group).
     *
     * @param  iterable<array{ingredient_id: int, direction: string, quantity: string, order_types?: int}>  $lines
     */
    public static function assertOptionRecordable(PrepGraph $graph, iterable $lines, string $optionName): void
    {
        $byDirection = [];
        foreach ($lines as $line) {
            $byDirection[$line['direction'].'|'.OrderTypes::read($line['order_types'] ?? null)][(int) $line['ingredient_id']] = $line['quantity'];
        }
        foreach ($byDirection as $directionLines) {
            self::assertRecordable($graph, $directionLines, '"'.$optionName.'" option');
        }
    }

    /** An exact amount, up to 10 decimals, trailing zeros trimmed. */
    private static function show(BigRational $value): string
    {
        return RecipeQuantity::trim($value->toScale(10, RoundingMode::HALF_UP));
    }

    private static function smallerUnit(string $unit): string
    {
        return match ($unit) {
            'kg' => 'g',
            'l' => 'ml',
            default => 'g or ml',
        };
    }
}
