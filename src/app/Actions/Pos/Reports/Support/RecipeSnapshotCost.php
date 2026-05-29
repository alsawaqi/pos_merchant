<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports\Support;

/**
 * Phase 8 payoff — derives COGS from the recipe / add-on ingredient
 * snapshots the device sale pipeline (pos_api) freezes onto each order line
 * at sale time. Because the cost is snapshotted, historical COGS stays
 * correct even after a merchant edits a recipe or an ingredient price.
 *
 * Snapshot shapes (written by pos_api's CreateOrderHandler):
 *   recipe_snapshot_json (order item) — list of
 *       {ingredient_id, qty, unit, unit_cost}; qty is the recipe amount per
 *       ONE product unit, unit_cost is OMR.
 *   ingredient_snapshot_json (order-item add-on) — a single
 *       {ingredient_id, qty, unit, unit_cost}.
 * Both are scaled by the order line's qty.
 *
 * Returns INTEGER BAISAS (1 OMR = 1000) and rounds once per line so summing
 * many lines never accumulates float drift. Reports read the raw JSON column
 * via the query builder (works on both sqlite text + Postgres jsonb).
 */
final class RecipeSnapshotCost
{
    /**
     * Cost in baisas of one order item's recipe snapshot, scaled by line qty.
     */
    public static function itemBaisas(?string $recipeSnapshotJson, float $itemQty): int
    {
        $perUnitOmr = 0.0;
        foreach (self::decodeLines($recipeSnapshotJson) as $line) {
            $perUnitOmr += ((float) ($line['qty'] ?? 0)) * ((float) ($line['unit_cost'] ?? 0));
        }

        return (int) round($perUnitOmr * $itemQty * 1000);
    }

    /**
     * Cost in baisas of one add-on's ingredient snapshot, scaled by line qty.
     */
    public static function addonBaisas(?string $ingredientSnapshotJson, float $itemQty): int
    {
        $snapshot = self::decode($ingredientSnapshotJson);
        if (! isset($snapshot['ingredient_id'])) {
            return 0;
        }
        $omr = ((float) ($snapshot['qty'] ?? 0)) * ((float) ($snapshot['unit_cost'] ?? 0));

        return (int) round($omr * $itemQty * 1000);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeLines(?string $json): array
    {
        $decoded = self::decode($json);

        return array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return array<mixed>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
