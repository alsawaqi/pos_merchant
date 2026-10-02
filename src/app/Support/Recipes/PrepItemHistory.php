<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Models\AuditLog;
use App\Models\Ingredient;

/**
 * LAUNCH-P3 P3-2 — a prep item's recipe history, the twin of
 * {@see ProductRecipeHistory}.
 *
 * Source: the append-only audit rows written by SavePrepItemAction —
 * `catalogue.prep_item.created` (version 1) and every
 * `catalogue.prep_item.recipe_updated` (version 2, 3, …). Each carries the
 * full lines before and after (lines_snapshot, in the entered unit), the
 * yield before and after, who (actor) and the note.
 */
final class PrepItemHistory
{
    /**
     * @return array{current: array{version: int, prep_yield_quantity: string, unit: string|null, lines: list<array<string, mixed>>}, versions: list<array<string, mixed>>}
     */
    public static function build(Ingredient $prep, RecipeQuantity $quantities): array
    {
        $rows = AuditLog::query()
            ->where('company_id', $prep->company_id)
            ->where('auditable_type', Ingredient::class)
            ->where('auditable_id', $prep->id)
            ->whereIn('event', ['catalogue.prep_item.created', 'catalogue.prep_item.recipe_updated'])
            ->with('actor:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $versions = [];
        foreach ($rows->values() as $index => $row) {
            $old = is_array($row->old_values) ? $row->old_values : [];
            $new = is_array($row->new_values) ? $row->new_values : [];
            $before = RecipeLineChanges::fromSnapshot(is_array($old['lines_snapshot'] ?? null) ? $old['lines_snapshot'] : null);
            $after = RecipeLineChanges::fromSnapshot(is_array($new['lines_snapshot'] ?? null) ? $new['lines_snapshot'] : null);

            $versions[] = [
                'version' => $index + 1,
                'event' => $row->event === 'catalogue.prep_item.created' ? 'created' : 'changed',
                'edited_at' => $row->created_at?->toIso8601String(),
                'edited_by' => $row->actor === null ? null : ['id' => (int) $row->actor->id, 'name' => (string) $row->actor->name],
                'note' => $new['note'] ?? null,
                'yield_before' => isset($old['prep_yield_quantity']) ? (string) $old['prep_yield_quantity'] : null,
                'yield_after' => isset($new['prep_yield_quantity']) ? (string) $new['prep_yield_quantity'] : null,
                'changes' => RecipeLineChanges::diff($before, $after),
            ];
        }

        $current = RecipeLineChanges::fromPrepLines($prep->prepRecipeLines()->with('ingredient')->get(), $quantities);

        return [
            'current' => [
                'version' => count($versions),
                'prep_yield_quantity' => (string) $prep->prep_yield_quantity,
                'unit' => $prep->unit?->value,
                'lines' => ProductRecipeHistory::lines($current),
            ],
            'versions' => array_reverse($versions),
        ];
    }
}
