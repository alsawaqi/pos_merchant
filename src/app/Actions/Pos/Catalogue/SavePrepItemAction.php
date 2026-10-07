<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use App\Models\IngredientRecipe;
use App\Models\User;
use App\Support\Catalogue\AllergenSync;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\ExplodedPrecision;
use App\Support\Recipes\PrepGraph;
use App\Support\Recipes\PrepUsage;
use App\Support\Recipes\RecipeEditGate;
use App\Support\Recipes\RecipeLineChanges;
use App\Support\Recipes\RecipeQuantity;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH-P3 P3-4 — create or edit a PREP ITEM: a name, a small base unit
 * (g, ml or piece), a yield (what one batch makes, in that unit) and a recipe
 * of ingredients or other prep items (per batch, P3-1 entered units).
 *
 * Rules (the data contract shared with pos_api):
 *   - every component belongs to the same company (company-scoped lookups);
 *   - a component may itself be a prep item, at most 3 levels deep;
 *   - cycles are refused (P cannot use itself, directly or indirectly);
 *   - yield > 0; no line rounds to 0 in its base unit;
 *   - fix order 1, M1-a: every dish and add-on option that uses the prep
 *     item, directly or through another prep item, still records each raw
 *     ingredient accurately per ONE unit sold ({@see ExplodedPrecision}).
 * Depth and cycles are checked on the WHOLE company graph with the edit
 * applied ({@see PrepGraph::assertValid()}), so deepening P can never push a
 * prep item that uses P past the limit.
 *
 * A prep item has no stock: is_prep = true, default_unit_cost stays 0 (its
 * cost is derived from its recipe) and it never gets a balance row.
 *
 * History (P3-2): the creation and every recipe / yield change write an audit
 * row (catalogue.prep_item.created / .recipe_updated) carrying the full
 * before / after lines in the entered unit, the yield and the note — the
 * prep item's history page reads those rows.
 *
 * A recipe / yield change bumps every product and add-on option that uses the
 * prep item, directly or through another prep item, so delta-syncing devices
 * pick up the new explosion.
 *
 * Needs "Edit recipes" (P3-3).
 */
final readonly class SavePrepItemAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private RecipeQuantity $quantities,
    ) {}

    /**
     * @param  array{name: string, name_ar?: ?string, unit: string, prep_yield_quantity: numeric-string|float|int, lines: list<array{ingredient_uuid: string, quantity: numeric-string|float|int, unit?: ?string}>, note?: ?string}  $attributes
     */
    public function create(array $attributes, User $actor): Ingredient
    {
        RecipeEditGate::ensure($actor);
        $companyId = $this->tenant->requiredId();

        $unit = IngredientUnit::from((string) $attributes['unit']);
        $yield = self::yield($attributes['prep_yield_quantity']);
        $resolved = $this->resolveLines($attributes['lines'], $companyId, null);
        $name = trim((string) $attributes['name']);

        $after = RecipeLineChanges::fromResolved($resolved, $this->quantities);
        $note = self::note($attributes['note'] ?? null);

        $prep = DB::transaction(function () use ($attributes, $companyId, $unit, $yield, $resolved, $name, $after, $note, $actor): Ingredient {
            // One prep save per company at a time, validated on the graph as
            // it is NOW: two concurrent saves could otherwise each pass and
            // together write a loop. A brand-new prep item cannot be in a
            // loop yet; its depth can be too great.
            self::lockCompany($companyId);
            self::assertComponentsLive($resolved);
            PrepGraph::load($companyId)
                ->withRecipe(0, $yield, self::graphLines($resolved), $name)
                ->assertValid();

            /** @var Ingredient $prep */
            $prep = Ingredient::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'name_ar' => self::nullable($attributes['name_ar'] ?? null),
                'unit' => $unit->value,
                'default_unit_cost' => '0',
                'min_stock_threshold' => null,
                'status' => 'active',
                'is_prep' => true,
                'prep_yield_quantity' => $yield,
            ]);
            $this->writeLines($prep, $resolved);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.prep_item.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Ingredient::class,
                auditableId: $prep->id,
                newValues: [
                    'name' => $prep->name,
                    'unit' => $unit->value,
                    'prep_yield_quantity' => $yield,
                    'lines' => RecipeLineChanges::readable($after),
                    'lines_snapshot' => RecipeLineChanges::snapshot($after),
                    'changes' => RecipeLineChanges::diff([], $after),
                    'note' => $note,
                ],
            ));

            return $prep;
        });

        PrepGraph::forget($companyId);

        return $prep->fresh(['prepRecipeLines.ingredient']);
    }

    /**
     * @param  array{name?: string, name_ar?: ?string, unit?: string, prep_yield_quantity?: numeric-string|float|int, lines?: list<array{ingredient_uuid: string, quantity: numeric-string|float|int, unit?: ?string}>, note?: ?string}  $attributes
     */
    public function update(Ingredient $prep, array $attributes, User $actor): Ingredient
    {
        RecipeEditGate::ensure($actor);
        $companyId = $this->tenant->requiredId();
        if ((int) $prep->company_id !== $companyId || ! $prep->isPrep()) {
            abort(404);
        }

        $oldYield = (string) (StockDecimal::format((string) ($prep->getRawOriginal('prep_yield_quantity') ?? ''), 0, 4) ?? '0');
        $yield = array_key_exists('prep_yield_quantity', $attributes) ? self::yield($attributes['prep_yield_quantity']) : $oldYield;

        $currentLines = $prep->prepRecipeLines()->with('ingredient')->get();
        $before = RecipeLineChanges::fromPrepLines($currentLines, $this->quantities);
        $resolved = array_key_exists('lines', $attributes)
            ? $this->resolveLines($attributes['lines'], $companyId, $prep)
            : null;
        $after = $resolved !== null ? RecipeLineChanges::fromResolved($resolved, $this->quantities) : $before;

        $recipeChanged = ! RecipeLineChanges::same($before, $after) || ! BigDecimal::of($yield)->isEqualTo($oldYield);

        $scalars = [];
        if (array_key_exists('name', $attributes) && trim((string) $attributes['name']) !== $prep->name) {
            $scalars['name'] = trim((string) $attributes['name']);
        }
        if (array_key_exists('name_ar', $attributes) && self::nullable($attributes['name_ar']) !== $prep->name_ar) {
            $scalars['name_ar'] = self::nullable($attributes['name_ar']);
        }
        if (array_key_exists('unit', $attributes) && $attributes['unit'] !== $prep->unit?->value) {
            $usage = PrepUsage::of($prep);
            if ($usage->total() > 0) {
                throw new RuntimeException(sprintf(
                    'Cannot change the unit of "%s": %s use it, in its current unit. Remove it from those recipes first.',
                    $prep->name,
                    $usage->describe(),
                ));
            }
            $scalars['unit'] = IngredientUnit::from((string) $attributes['unit'])->value;
        }

        if (! $recipeChanged && $scalars === []) {
            return $prep->fresh(['prepRecipeLines.ingredient']);
        }

        $note = self::note($attributes['note'] ?? null);

        $saved = DB::transaction(function () use ($prep, $scalars, $recipeChanged, $resolved, $yield, $oldYield, $before, $after, $note, $actor, $companyId): Ingredient {
            if ($recipeChanged) {
                // Serialised per company and checked on the current graph (see create()).
                self::lockCompany($companyId);
                if ($resolved !== null) {
                    self::assertComponentsLive($resolved);
                }
                $graphLines = $resolved !== null
                    ? self::graphLines($resolved)
                    : array_map(static fn (array $l): string => $l['quantity'], $after);
                $graph = PrepGraph::load($companyId)
                    ->withRecipe((int) $prep->id, $yield, $graphLines, $scalars['name'] ?? $prep->name);
                $graph->assertValid((int) $prep->id);
                // LAUNCH-P3 M1-a — every dish and option that uses this prep
                // item (directly or through another prep item) must still
                // record accurately per ONE unit with the new explosion.
                ExplodedPrecision::assertUsersRecordable($graph, (int) $prep->id);
            }

            $oldScalars = [];
            foreach (array_keys($scalars) as $field) {
                $oldScalars[$field] = $field === 'unit' ? $prep->unit?->value : $prep->{$field};
            }

            if ($scalars !== []) {
                $prep->forceFill($scalars);
            }
            if ($recipeChanged) {
                $prep->forceFill(['prep_yield_quantity' => $yield]);
            }
            $prep->save();

            if ($recipeChanged && $resolved !== null) {
                $prep->prepRecipeLines()->delete();
                $this->writeLines($prep, $resolved);
            }

            if ($scalars !== []) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.prep_item.updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Ingredient::class,
                    auditableId: $prep->id,
                    oldValues: $oldScalars,
                    newValues: $scalars,
                ));
            }

            if ($recipeChanged) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.prep_item.recipe_updated',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Ingredient::class,
                    auditableId: $prep->id,
                    oldValues: [
                        'prep_yield_quantity' => $oldYield,
                        'lines' => RecipeLineChanges::readable($before),
                        'lines_snapshot' => RecipeLineChanges::snapshot($before),
                    ],
                    newValues: [
                        'prep_yield_quantity' => $yield,
                        'lines' => RecipeLineChanges::readable($after),
                        'lines_snapshot' => RecipeLineChanges::snapshot($after),
                        'changes' => RecipeLineChanges::diff($before, $after),
                        'note' => $note,
                    ],
                ));

                // Delta visibility for the devices: everything that explodes
                // through this prep item now explodes differently.
                PrepUsage::touchDependents($prep);
                // LAUNCH costs & allergens add-on — and whatever uses those
                // products (components, add-on options), for the allergens.
                AllergenSync::touch($companyId, ingredientIds: [(int) $prep->id]);
            }

            return $prep;
        });

        PrepGraph::forget($companyId);

        return $saved->fresh(['prepRecipeLines.ingredient']);
    }

    /**
     * @param  list<array{ingredient_uuid: string, quantity: numeric-string|float|int, unit?: ?string}>  $lines
     * @return list<array{ingredient: Ingredient, sort_order: int, quantity: string, entered_unit: string, entered_quantity: string}>
     */
    private function resolveLines(array $lines, int $companyId, ?Ingredient $prep): array
    {
        if ($lines === []) {
            throw new RuntimeException('A prep item needs at least one ingredient in its recipe.');
        }

        $uuids = array_map(static fn (array $l): string => (string) $l['ingredient_uuid'], $lines);
        if (count($uuids) !== count(array_unique($uuids))) {
            throw new RuntimeException('Each ingredient can appear at most once in a prep recipe — merge the lines first.');
        }

        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');
        if ($ingredients->count() !== count($uuids)) {
            throw new RuntimeException('One or more ingredients in the prep recipe do not belong to your company.');
        }

        $resolved = [];
        foreach ($lines as $idx => $line) {
            /** @var Ingredient $ingredient */
            $ingredient = $ingredients[(string) $line['ingredient_uuid']];
            if ($prep !== null && (int) $ingredient->id === (int) $prep->id) {
                throw new RuntimeException(sprintf('A prep item cannot use itself: remove "%s" from its own recipe.', $prep->name));
            }
            $resolved[] = ['ingredient' => $ingredient, 'sort_order' => $idx]
                + $this->quantities->resolve($ingredient, $line['quantity'], $line['unit'] ?? null);
        }

        return $resolved;
    }

    /**
     * @param  list<array{ingredient: Ingredient, sort_order: int, quantity: string, entered_unit: string, entered_quantity: string}>  $resolved
     */
    private function writeLines(Ingredient $prep, array $resolved): void
    {
        foreach ($resolved as $line) {
            IngredientRecipe::query()->create([
                'prep_ingredient_id' => $prep->id,
                'ingredient_id' => $line['ingredient']->id,
                'quantity' => $line['quantity'],
                'entered_unit' => $line['entered_unit'],
                'entered_quantity' => $line['entered_quantity'],
                'sort_order' => $line['sort_order'],
            ]);
        }
    }

    /**
     * @param  list<array{ingredient: Ingredient, quantity: string}>  $resolved
     * @return array<int, string>
     */
    private static function graphLines(array $resolved): array
    {
        $out = [];
        foreach ($resolved as $line) {
            $out[(int) $line['ingredient']->id] = $line['quantity'];
        }

        return $out;
    }

    /**
     * Under the company lock: no component was deleted since it was resolved.
     *
     * @param  list<array{ingredient: Ingredient}>  $resolved
     */
    private static function assertComponentsLive(array $resolved): void
    {
        $ids = array_map(static fn (array $l): int => (int) $l['ingredient']->id, $resolved);
        if (Ingredient::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw new RuntimeException('An ingredient in the prep recipe was just deleted — reload and try again.');
        }
    }

    /** Row lock on the company: prep recipe saves of one company run one at a time (no-op on SQLite). */
    private static function lockCompany(int $companyId): void
    {
        DB::table('pos_companies')->where('id', $companyId)->lockForUpdate()->first(['id']);
    }

    private static function yield(string|int|float $value): string
    {
        $yield = BigDecimal::of(trim((string) $value) === '' ? '0' : trim((string) $value));
        if (! $yield->isPositive()) {
            throw new RuntimeException('The yield (what one batch makes) must be more than 0.');
        }

        return (string) StockDecimal::format((string) $yield, 0, 4);
    }

    private static function note(?string $note): ?string
    {
        return $note !== null && trim($note) !== '' ? trim($note) : null;
    }

    private static function nullable(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
