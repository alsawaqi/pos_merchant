<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH review add-on (A2, tester call 5) — a container's SIZE is locked once
 * it is used: in a purchase line, the breakdown (a balance or its ledger), a
 * transfer / count / waste / restock row, a recipe line that names it (by
 * token or by its name), or as the content of another live container. The
 * merchant adds a new container instead; the name and Arabic name stay
 * editable. One query per kind of reference, whatever the number of
 * containers.
 */
final class ContainerUsage
{
    public const MESSAGE = 'This container is already used (purchases, stock, counts, transfers, waste, restock requests or recipes) or the tills count in it, so its size cannot change. Add a new container with the new size instead.';

    public static function isUsed(IngredientAltUnit $container): bool
    {
        return isset(self::usedIds((int) $container->ingredient_id)[(int) $container->id]);
    }

    /**
     * The ids of the ingredient's containers whose size can no longer change.
     *
     * @return array<int, true>
     */
    public static function usedIds(int $ingredientId): array
    {
        $containers = IngredientAltUnit::withTrashed()->where('ingredient_id', $ingredientId)->get();
        if ($containers->isEmpty()) {
            return [];
        }
        $ids = $containers->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $used = [];
        $mark = static function (iterable $values) use (&$used): void {
            foreach ($values as $id) {
                if ($id !== null) {
                    $used[(int) $id] = true;
                }
            }
        };

        foreach ([
            'pos_purchase_receipt_lines',
            'pos_stock_container_balances',
            'pos_stock_container_movements',
            'pos_branch_transfer_line_containers',
            'pos_stock_count_line_containers',
            'pos_waste_records',
            'pos_restock_request_lines',
        ] as $table) {
            $mark(DB::table($table)->whereIn('container_id', $ids)->distinct()->pluck('container_id'));
        }

        // Fix order B-1 (M3) — the container the tills count in is always
        // locked: a till's offline count of "3 bags" (counted_pieces, no
        // container row) is converted at the size it has when it syncs, and
        // '@piece' recipe lines and receipts name it too.
        $mark(DB::table('pos_ingredients')->where('id', $ingredientId)->whereNotNull('count_container_id')->pluck('count_container_id'));

        // The content of another LIVE container: resizing a bottle would
        // silently break "crate holds 12 × bottle".
        $mark($containers->filter(static fn (IngredientAltUnit $c): bool => ! $c->trashed() && $c->contains_unit_id !== null)->pluck('contains_unit_id'));

        // Recipe lines naming a container: by token, or by a name.
        $byToken = [];
        $byName = [];
        foreach ($containers as $container) {
            $byToken[$container->token()] = (int) $container->id;
            $byName[(string) $container->name][] = (int) $container->id;
        }
        foreach (['pos_product_recipes', 'pos_addon_consumptions', 'pos_ingredient_recipes'] as $table) {
            $tokens = DB::table($table)->where('ingredient_id', $ingredientId)->whereNotNull('entered_unit')->distinct()->pluck('entered_unit');
            foreach ($tokens as $token) {
                $token = (string) $token;
                if (isset($byToken[$token])) {
                    $used[$byToken[$token]] = true;
                } elseif (isset($byName[$token])) {
                    foreach ($byName[$token] as $id) {
                        $used[$id] = true;
                    }
                }
            }
        }

        return $used;
    }

    /** The count container of an ingredient also counts as "used" by devices only for the warning, not the lock. */
    public static function isCountContainer(Ingredient $ingredient, IngredientAltUnit $container): bool
    {
        return $ingredient->count_container_id !== null && (int) $ingredient->count_container_id === (int) $container->id;
    }
}
