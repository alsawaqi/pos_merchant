<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeVersion;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5b — atomically replace a product's recipe.
 *
 * Idempotent: caller PUTs the full desired set of recipe
 * lines. We:
 *   1. Resolve every ingredient_uuid → ingredient_id +
 *      verify tenant ownership. Bogus or cross-tenant uuid
 *      aborts the whole replace (no partial writes).
 *   2. Compare the new shape to what's already on disk. If
 *      identical (same ingredients + same quantities), skip
 *      everything (no audit, no version row, no DB writes).
 *   3. Snapshot the CURRENT (pre-edit) recipe into
 *      pos_product_recipe_versions with denormalised
 *      ingredient name + current unit_cost_at_time. This is
 *      what makes historical COGS resilient to later
 *      ingredient edits / deletions.
 *   4. Delete the existing recipe lines + insert the new ones.
 *      Wrapped in DB::transaction so a mid-write failure
 *      leaves the recipe in its pre-edit state.
 *   5. Write the audit row.
 *
 * Empty array = "no recipe / pre-made goods". Phase 8 order
 * pipeline checks hasRecipe() before writing sale-consumption
 * movements, so empty is a valid terminal state.
 *
 * Duplicate ingredients in the payload → 422 (the caller
 * should sum them client-side; we don't silently merge).
 *
 * Audit event: catalogue.product.recipe_updated. Payload
 * captures old + new line counts + which ingredient_ids
 * changed, so the audit log surfaces "what was modified"
 * without dumping the full recipe twice.
 */
final readonly class UpdateProductRecipeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<int, array{ingredient_uuid: string, quantity: numeric-string|float|int}>  $lines
     */
    public function handle(Product $product, array $lines, User $actor, ?string $note = null): Product
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }

        // De-dupe check — caller's responsibility to merge
        // identical ingredient_uuids upstream.
        $uuids = array_map(static fn (array $l): string => (string) $l['ingredient_uuid'], $lines);
        if (count($uuids) !== count(array_unique($uuids))) {
            throw new RuntimeException('Duplicate ingredient in recipe payload — merge them client-side first.');
        }

        // Resolve UUIDs → models in one query. Any bogus or
        // cross-tenant uuid breaks the count and we abort.
        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');
        if ($ingredients->count() !== count($uuids)) {
            throw new RuntimeException('One or more ingredients in the recipe do not belong to your company.');
        }

        // Build a normalised representation of the new recipe
        // for diff comparison: [(ingredient_id => quantity)].
        $newShape = collect($lines)->mapWithKeys(static function (array $l) use ($ingredients): array {
            /** @var Ingredient $ing */
            $ing = $ingredients[$l['ingredient_uuid']];
            return [$ing->id => (string) $l['quantity']];
        });

        $currentShape = $product->recipeLines()
            ->get(['ingredient_id', 'quantity'])
            ->mapWithKeys(static fn (ProductRecipe $r): array => [
                (int) $r->ingredient_id => (string) $r->quantity,
            ]);

        // No-op skip — identical recipe = no version, no audit,
        // no DB churn.
        if ($this->shapesEqual($currentShape, $newShape)) {
            return $product->fresh(['recipeLines.ingredient']);
        }

        return DB::transaction(function () use (
            $product,
            $lines,
            $ingredients,
            $newShape,
            $currentShape,
            $actor,
            $note,
            $companyId,
        ): Product {
            // Step 1: snapshot the PRE-edit recipe as a version.
            // Empty array is a valid snapshot (means "previous
            // state was no recipe") — Phase 8 won't look at
            // versions for that case but the audit trail
            // benefits from the explicit zero state.
            $snapshot = $this->buildSnapshot($product);
            ProductRecipeVersion::query()->create([
                'product_id' => $product->id,
                'recipe_json' => $snapshot,
                'edited_by_user_id' => $actor->getKey(),
                'note' => $note,
                'edited_at' => now(),
            ]);

            // Step 2: wipe + re-insert. Cleaner than diffing
            // individual rows because the recipe is small
            // (typically 1-8 ingredients per product).
            $product->recipeLines()->delete();
            foreach ($lines as $idx => $l) {
                /** @var Ingredient $ing */
                $ing = $ingredients[$l['ingredient_uuid']];
                ProductRecipe::query()->create([
                    'product_id' => $product->id,
                    'ingredient_id' => $ing->id,
                    'quantity' => (string) $l['quantity'],
                    // Denormalised — survives later unit edits
                    // on the ingredient master.
                    'unit_at_set' => $ing->unit?->value,
                    'sort_order' => $idx,
                ]);
            }

            // Step 3: audit row summarising the change.
            $oldIds = $currentShape->keys()->all();
            $newIds = $newShape->keys()->all();
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.recipe_updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: $product->id,
                oldValues: [
                    'line_count' => count($oldIds),
                    'ingredient_ids' => $oldIds,
                ],
                newValues: [
                    'line_count' => count($newIds),
                    'ingredient_ids' => $newIds,
                ],
            ));

            return $product->fresh(['recipeLines.ingredient']);
        });
    }

    /**
     * @param  Collection<int, string>  $a
     * @param  Collection<int, string>  $b
     */
    private function shapesEqual(Collection $a, Collection $b): bool
    {
        if ($a->count() !== $b->count()) {
            return false;
        }
        foreach ($a as $ingredientId => $qty) {
            if (! $b->has($ingredientId)) {
                return false;
            }
            // Decimal-string equality — never compare floats.
            if ((string) $b[$ingredientId] !== (string) $qty) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int, array{ingredient_id: int, ingredient_name: string, quantity: string, unit: string, unit_cost_at_time: string}>
     */
    private function buildSnapshot(Product $product): array
    {
        return $product->recipeLines()
            ->with('ingredient')
            ->get()
            ->map(static fn (ProductRecipe $r): array => [
                'ingredient_id' => (int) $r->ingredient_id,
                'ingredient_name' => $r->ingredient?->name ?? '',
                'quantity' => (string) $r->quantity,
                'unit' => $r->unit_at_set?->value ?? '',
                'unit_cost_at_time' => (string) ($r->ingredient?->default_unit_cost ?? '0.000'),
            ])
            ->values()
            ->all();
    }
}
