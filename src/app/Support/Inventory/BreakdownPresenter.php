<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\StockContainerBalance;
use App\Support\StockDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * LAUNCH review add-on (B2) — the breakdown under a live total, as the stock
 * pages show it: "2 × bottle 1.5 l + 3 × bottle 500 ml · counted 4 Oct". It
 * is what was on the shelf at the last purchase, transfer or count (recipe
 * use lowers only the total), so its amounts may add up to more than the
 * total; it is never used to compute stock.
 */
final class BreakdownPresenter
{
    /**
     * Balances (pieces > 0) of many ingredients at one location, grouped:
     * ingredient id => breakdown rows. One query for the balances and one for
     * their containers.
     *
     * @param  list<int>  $ingredientIds
     * @return array<int, list<array<string, mixed>>>
     */
    public static function forLocation(int $companyId, ?int $branchId, array $ingredientIds): array
    {
        if ($ingredientIds === []) {
            return [];
        }
        $balances = StockContainerBalance::query()
            ->where('company_id', $companyId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->when($branchId === null, static fn ($q) => $q->whereNull('branch_id'), static fn ($q) => $q->where('branch_id', $branchId))
            ->where('pieces', '>', 0)
            ->orderBy('id')
            ->get();

        return self::group($balances);
    }

    /**
     * Every location of one ingredient: 'warehouse' and branch id => rows.
     *
     * @return array<string|int, list<array<string, mixed>>>
     */
    public static function forIngredient(Ingredient $ingredient): array
    {
        $balances = StockContainerBalance::query()
            ->where('company_id', (int) $ingredient->company_id)
            ->where('ingredient_id', $ingredient->id)
            ->where('pieces', '>', 0)
            ->orderBy('id')
            ->get();

        $containers = self::containers($balances);
        $out = [];
        foreach ($balances as $balance) {
            $key = $balance->branch_id === null ? 'warehouse' : (int) $balance->branch_id;
            $row = self::row($balance, $containers, $ingredient);
            if ($row !== null) {
                $out[$key][] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, StockContainerBalance>  $balances
     * @return array<int, list<array<string, mixed>>>
     */
    private static function group(Collection $balances): array
    {
        $containers = self::containers($balances);
        $ingredients = Ingredient::withTrashed()->whereIn('id', $balances->pluck('ingredient_id')->unique()->all())->get()->keyBy('id');
        $out = [];
        foreach ($balances as $balance) {
            $ingredient = $ingredients->get($balance->ingredient_id);
            if ($ingredient === null) {
                continue;
            }
            $row = self::row($balance, $containers, $ingredient);
            if ($row !== null) {
                $out[(int) $balance->ingredient_id][] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, StockContainerBalance>  $balances
     * @return Collection<int, IngredientAltUnit>
     */
    private static function containers(Collection $balances): Collection
    {
        $ids = $balances->pluck('container_id')->unique()->all();
        $loaded = IngredientAltUnit::withTrashed()->whereIn('id', $ids)->get();
        // Content rows (for "crate (12 × bottle)") never appear: balances are leaves.
        return $loaded->keyBy('id');
    }

    /**
     * @param  Collection<int, IngredientAltUnit>  $containers
     * @return array<string, mixed>|null
     */
    private static function row(StockContainerBalance $balance, Collection $containers, Ingredient $ingredient): ?array
    {
        $container = $containers->get($balance->container_id);
        if ($container === null) {
            return null;
        }
        $pieces = Containers::decimal($balance->getRawOriginal('pieces'));
        $amount = $pieces->multipliedBy(Containers::decimal((string) $container->factor))->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);

        return [
            'container_uuid' => $container->uuid,
            'display_name' => Containers::displayName($container, $ingredient, $containers, 'en'),
            'display_name_ar' => Containers::displayName($container, $ingredient, $containers, 'ar'),
            'removed' => $container->trashed(),
            'pieces' => Containers::trim((string) $pieces->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP)),
            'amount' => (string) StockDecimal::quantity((string) $amount),
        ];
    }
}
