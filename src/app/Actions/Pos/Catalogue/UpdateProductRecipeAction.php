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
use App\Support\Recipes\ExplodedPrecision;
use App\Support\Recipes\PrepGraph;
use App\Support\Recipes\RecipeEditGate;
use App\Support\Recipes\RecipeLineChanges;
use App\Support\Recipes\RecipeQuantity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5b — atomically replace a product's recipe.
 *
 * Idempotent: caller PUTs the full desired set of recipe
 * lines. We:
 *   1. Resolve every ingredient_uuid → ingredient_id +
 *      verify tenant ownership. Bogus or cross-tenant uuid
 *      aborts the whole replace (no partial writes). The
 *      ingredient may be a PREP ITEM (LAUNCH-P3 P3-4); pos_api
 *      explodes it into raw ingredients when an order is copied.
 *   2. Convert each line to the BASE unit and keep how it was
 *      typed (LAUNCH-P3 P3-1, {@see RecipeQuantity}); an amount
 *      that rounds to 0 in the base unit is refused (422).
 *   3. Compare the new shape to what's already on disk (base
 *      quantity AND the entered form). If identical, skip
 *      everything (no audit, no version row, no DB writes).
 *      What is on disk is read inside the transaction, under a
 *      row lock on the product (fix order 1, K5).
 *   4. Snapshot the CURRENT (pre-edit) recipe into
 *      pos_product_recipe_versions with denormalised ingredient
 *      name + current unit_cost_at_time (+ LAUNCH-P3: the entered
 *      unit / quantity), dated at the edit. pos_api reads these
 *      rows as "the recipe in force before this moment" (P3-6),
 *      so the keys it reads (ingredient_id, quantity, unit) keep
 *      their meaning: the BASE quantity and unit.
 *   5. Delete the existing recipe lines + insert the new ones.
 *      Wrapped in DB::transaction so a mid-write failure
 *      leaves the recipe in its pre-edit state.
 *   6. Write the audit row — LAUNCH-P3 P3-2: with every line
 *      added, removed or changed (before → after), so a
 *      150 g → 120 g edit leaves a visible trace, and the note.
 *
 * Empty array = "no recipe / pre-made goods". Phase 8 order
 * pipeline checks hasRecipe() before writing sale-consumption
 * movements, so empty is a valid terminal state.
 *
 * Duplicate ingredients in the payload → 422 (the caller
 * should sum them client-side; we don't silently merge).
 *
 * LAUNCH-P3 P3-3 — changing a recipe needs the "Edit recipes"
 * permission ({@see RecipeEditGate}); a no-op never does.
 *
 * Audit event: catalogue.product.recipe_updated.
 */
final readonly class UpdateProductRecipeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private RecipeQuantity $quantities,
        private SyncRemovableIngredientsAction $removable,
    ) {}

    /**
     * @param  array<int, array{ingredient_uuid: string, quantity: numeric-string|float|int, unit?: ?string}>  $lines
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

        // Base quantity (what the device deducts) + the entered form, per line.
        $resolved = [];
        foreach ($lines as $idx => $l) {
            /** @var Ingredient $ing */
            $ing = $ingredients[$l['ingredient_uuid']];
            $resolved[] = ['ingredient' => $ing, 'sort_order' => $idx]
                + $this->quantities->resolve($ing, $l['quantity'], $l['unit'] ?? null);
        }

        $after = RecipeLineChanges::fromResolved($resolved, $this->quantities);

        return DB::transaction(function () use ($product, $resolved, $after, $actor, $note, $companyId): Product {
            // LAUNCH-P3 K5 — lock the product, THEN read the recipe this save
            // replaces: two saves of one product run one after the other, so
            // the version row always holds the state the edit really replaced
            // (read outside the lock, a concurrent save could slip in between
            // and vanish from the history). No-op on SQLite.
            Product::query()->whereKey($product->id)->lockForUpdate()->first(['id']);
            $current = $product->recipeLines()->with('ingredient')->get();
            $before = RecipeLineChanges::fromProductLines($current, $this->quantities);

            // No-op skip — identical recipe = no version, no audit,
            // no DB churn (a pre-P3 line re-saved in its base unit is identical).
            if (RecipeLineChanges::same($before, $after)) {
                return $product->fresh(['recipeLines.ingredient']);
            }

            RecipeEditGate::ensure($actor);

            // LAUNCH-P3 M1-a — a prep line must still record accurately once it is
            // exploded per ONE unit sold (the copy pos_api freezes at sale).
            $perUnit = [];
            foreach ($resolved as $line) {
                $perUnit[(int) $line['ingredient']->id] = $line['quantity'];
            }
            ExplodedPrecision::assertRecordable(PrepGraph::forCompany($companyId), $perUnit, '"'.$product->name.'"');

            // Step 1: snapshot the PRE-edit recipe as a version.
            // Empty array is a valid snapshot (means "previous
            // state was no recipe").
            ProductRecipeVersion::query()->create([
                'product_id' => $product->id,
                'recipe_json' => RecipeLineChanges::snapshot($before),
                'edited_by_user_id' => $actor->getKey(),
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'edited_at' => now(),
            ]);

            // Step 2: wipe + re-insert. Cleaner than diffing
            // individual rows because the recipe is small
            // (typically 1-8 ingredients per product).
            $product->recipeLines()->delete();
            foreach ($resolved as $line) {
                /** @var Ingredient $ing */
                $ing = $line['ingredient'];
                ProductRecipe::query()->create([
                    'product_id' => $product->id,
                    'ingredient_id' => $ing->id,
                    // #13 — consumption in the ingredient's BASE unit (what the
                    // device + pos_api deduct in); unit_at_set stays base.
                    'quantity' => $line['quantity'],
                    'unit_at_set' => $ing->unit?->value,
                    'sort_order' => $line['sort_order'],
                    // LAUNCH-P3 P3-1 — how it was typed.
                    'entered_unit' => $line['entered_unit'],
                    'entered_quantity' => $line['entered_quantity'],
                ]);
            }

            // Delta visibility: the device config re-emits a product (and its
            // embedded recipe + recomputed low_stock) only when
            // pos_products.updated_at moves — recipe rows have no delta gate
            // of their own, so an untouched product would leave delta devices
            // gating availability on the stale recipe until their next full
            // sync.
            $product->touch();

            // LAUNCH review add-on — a deleted recipe line can no longer be
            // removed: retire its "NO …" option (the others stay).
            $this->removable->retireDroppedLines(
                $product,
                array_map(static fn (array $line): int => (int) $line['ingredient']->id, $resolved),
                $actor,
            );

            // Step 3: audit row — counts + ids (as before) and, since
            // LAUNCH-P3, every line change in the entered unit + the note.
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.recipe_updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: $product->id,
                oldValues: [
                    'line_count' => count($before),
                    'ingredient_ids' => array_keys($before),
                    'lines' => RecipeLineChanges::readable($before),
                ],
                newValues: [
                    'line_count' => count($after),
                    'ingredient_ids' => array_keys($after),
                    'lines' => RecipeLineChanges::readable($after),
                    'changes' => RecipeLineChanges::diff($before, $after),
                    'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                ],
            ));

            return $product->fresh(['recipeLines.ingredient']);
        });
    }
}
