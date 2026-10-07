<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\AddOnSelectionMode;
use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\AddOnGroup;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalogue\AddOnKindRules;
use App\Support\Catalogue\RemovableIngredients;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH review add-on (D12) — keep a product's own Remove group in step with
 * the recipe lines ticked "Can be removed" (see {@see RemovableIngredients}).
 *
 *   - A ticked line gets (or keeps) one price-0 option "NO {label}" /
 *     "بدون {label_ar}" whose removes_ingredient_id is the line's ingredient;
 *     a changed label renames it.
 *   - An unticked line's option is retired (soft-deleted: past orders keep
 *     their add-on name and id).
 *   - The group is created on the first tick (bound to the product like its
 *     other own groups) and retired with its last option; a later tick
 *     brings the same group back, so its name and ids never churn.
 *
 * Only ingredients of the product's current recipe can be ticked. Catalogue
 * permission (not "Edit recipes": no amount changes). The product is touched
 * (devices pick it up by delta) and one audit row records before / after.
 *
 * Audit event: catalogue.product.removable_saved.
 */
final readonly class SyncRemovableIngredientsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  list<array{ingredient_uuid: string, label?: string|null, label_ar?: string|null}>  $lines
     * @param  list<array{ingredient_uuid: string, label?: string|null, label_ar?: string|null}>|null  $expected
     *                                                                                                            the ticks the page loaded with (fix order C-1, M1): when they no
     *                                                                                                            longer match what is saved, nothing is written and
     *                                                                                                            {@see StaleRemovableTicksException} is thrown (HTTP 409). Null
     *                                                                                                            only for a brand-new product (the wizard's create).
     * @return array{applies_to_stock: bool, lines: list<array<string, mixed>>}
     */
    public function handle(Product $product, array $lines, User $actor, ?array $expected = null): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }
        if ($product->isCombo() && $lines !== []) {
            throw new RuntimeException('A combo has no recipe of its own: its items keep their own Remove lists.');
        }

        $uuids = array_map(static fn (array $l): string => (string) $l['ingredient_uuid'], $lines);
        if (count($uuids) !== count(array_unique($uuids))) {
            throw new RuntimeException('An ingredient is ticked twice.');
        }
        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');
        if ($ingredients->count() !== count($uuids)) {
            throw new RuntimeException('One or more ingredients do not belong to your company.');
        }
        $inRecipe = $product->recipeLines()->pluck('ingredient_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($ingredients as $ingredient) {
            if (! in_array((int) $ingredient->id, $inRecipe, true)) {
                throw new RuntimeException(sprintf('"%s" is not in this product\'s recipe, so it cannot be marked "Can be removed".', $ingredient->name));
            }
        }
        // Fix order C-1, L2 — two chips with the same text would do different
        // things: every final name is unique (EN and AR, any case).
        $duplicate = RemovableIngredients::duplicateName($lines, $ingredients->all());
        if ($duplicate !== null) {
            throw new RuntimeException(RemovableIngredients::duplicateMessage($duplicate['name']));
        }

        DB::transaction(function () use ($product, $lines, $ingredients, $actor, $companyId, $expected): void {
            Product::query()->whereKey($product->id)->lockForUpdate()->first(['id']);
            // Fix order C-1, M1 — refuse a save made from ticks that are no
            // longer the saved ones (another manager, another tab).
            if ($expected !== null && ! RemovableIngredients::matchesSaved($product, $expected)) {
                throw new StaleRemovableTicksException;
            }
            $before = $this->snapshot($product);

            $group = RemovableIngredients::group($product, withTrashed: true);
            if ($group === null && $lines === []) {
                return;
            }
            if ($group === null) {
                $group = $this->createGroup($product, $companyId);
            } elseif ($group->trashed() && $lines !== []) {
                $group->restore();
            }
            if ($lines !== []) {
                $group->products()->syncWithoutDetaching([$product->id]);
            }

            $existing = AddOn::query()->withTrashed()
                ->where('add_on_group_id', $group->id)
                ->whereNotNull('removes_ingredient_id')
                ->orderBy('id')
                ->get()
                ->keyBy(fn (AddOn $a): int => (int) $a->removes_ingredient_id);

            $keep = [];
            foreach (array_values($lines) as $order => $line) {
                /** @var Ingredient $ingredient */
                $ingredient = $ingredients[(string) $line['ingredient_uuid']];
                $keep[] = (int) $ingredient->id;
                $attributes = [
                    'name' => RemovableIngredients::optionName($ingredient, $line['label'] ?? null),
                    'name_ar' => RemovableIngredients::optionNameAr($ingredient, $line['label_ar'] ?? null, $line['label'] ?? null),
                    // LAUNCH combo add-on — 0 or a minus price; a line sent
                    // without one keeps the saved price (0 when new).
                    'price_delta' => array_key_exists('price', $line) && $line['price'] !== null
                        ? number_format(min(0.0, (float) $line['price']), 3, '.', '')
                        : number_format((float) ($existing->get((int) $ingredient->id)?->price_delta ?? 0), 3, '.', ''),
                    'is_default' => false,
                    // Fix order C-1, M4 — a "NO …" option never takes stock:
                    // no linked product and no legacy single ingredient
                    // (its consumption lines are cleared below).
                    'linked_product_id' => null,
                    'ingredient_id' => null,
                    'ingredient_qty' => null,
                    'ingredient_unit' => null,
                    'display_order' => $order,
                    'status' => 'active',
                ];
                /** @var AddOn|null $option */
                $option = $existing->get((int) $ingredient->id);
                if ($option === null) {
                    AddOn::query()->create($attributes + [
                        'company_id' => $companyId,
                        'add_on_group_id' => $group->id,
                        'removes_ingredient_id' => $ingredient->id,
                    ]);

                    continue;
                }
                if ($option->trashed()) {
                    $option->restore();
                }
                $option->forceFill($attributes);
                if ($option->isDirty()) {
                    $option->save();
                }
                AddOnConsumption::query()->where('add_on_id', $option->id)->delete();
            }

            $this->retire($group, $keep);

            $after = $this->snapshot($product);
            if ($before === $after) {
                return;
            }
            // Delta visibility: options ride their group, the group ids ride
            // the product row — move both.
            $group->touch();
            $product->touch();
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.removable_saved',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: $product->id,
                oldValues: ['removable' => $before],
                newValues: ['removable' => $after],
            ));
        });

        return RemovableIngredients::state($product->fresh());
    }

    /**
     * A recipe save dropped lines: retire their "NO …" options (the recipe
     * action calls this after writing the new lines).
     *
     * @param  list<int>  $keepIngredientIds  the ingredients still in the recipe
     */
    public function retireDroppedLines(Product $product, array $keepIngredientIds, User $actor): void
    {
        $group = RemovableIngredients::group($product);
        if ($group === null) {
            return;
        }
        $before = $this->snapshot($product);
        $this->retire($group, $keepIngredientIds);
        $after = $this->snapshot($product);
        if ($before === $after) {
            return;
        }
        $group->touch();
        $product->touch();
        $this->writeAuditLog->handle(new AuditLogData(
            event: 'catalogue.product.removable_saved',
            actorUserId: $actor->getKey(),
            companyId: (int) $product->company_id,
            auditableType: Product::class,
            auditableId: $product->id,
            oldValues: ['removable' => $before],
            newValues: ['removable' => $after, 'reason' => 'recipe line removed'],
        ));
    }

    /**
     * Soft-delete the options whose ingredient is not kept, and the group
     * once it holds no option (an empty group would show as an empty list
     * on today's devices).
     *
     * @param  list<int>  $keepIngredientIds
     */
    private function retire(AddOnGroup $group, array $keepIngredientIds): void
    {
        AddOn::query()
            ->where('add_on_group_id', $group->id)
            ->where(function ($q) use ($keepIngredientIds): void {
                $q->whereNull('removes_ingredient_id')
                    ->orWhereNotIn('removes_ingredient_id', $keepIngredientIds === [] ? [0] : $keepIngredientIds);
            })
            ->get()
            ->each(fn (AddOn $option) => $option->delete());

        if (! $group->trashed() && ! AddOn::query()->where('add_on_group_id', $group->id)->exists()) {
            $group->delete();
        }
    }

    private function createGroup(Product $product, int $companyId): AddOnGroup
    {
        // Owned group names are unique per product, soft-deleted ones
        // included (pos_addon_groups_owner_name_unique): the merchant may
        // already own a group called "Remove".
        $name = RemovableIngredients::freeGroupName($companyId, (int) $product->id);

        /** @var AddOnGroup $group */
        $group = AddOnGroup::query()->create([
            'company_id' => $companyId,
            'owner_product_id' => $product->id,
            'kind' => AddOnGroup::KIND_REMOVE,
            'name' => $name,
            'name_ar' => RemovableIngredients::GROUP_NAME_AR,
            'selection_mode' => AddOnSelectionMode::Multi->value,
            'min_selections' => null,
            'max_selections' => null,
            'is_global' => false,
            'display_order' => 0,
            'status' => 'active',
        ]);

        return $group;
    }

    /**
     * Fix order C-1, L3 — the merchant gives one of the product's own groups
     * (new or renamed) the name its hidden Remove list holds. Owned names are
     * unique per product in the database whatever the kind
     * (pos_addon_groups_owner_name_unique, soft-deleted rows included), so
     * the Remove list moves to the next free name ("Remove 2") first,
     * audited and touched so devices pick the new name up by delta.
     */
    public function yieldName(int $companyId, int $ownerProductId, string $name, User $actor): void
    {
        $group = AddOnGroup::query()->withTrashed()
            ->where('company_id', $companyId)
            ->where('owner_product_id', $ownerProductId)
            ->where('kind', AddOnGroup::KIND_REMOVE)
            ->where('name', $name)
            ->first();
        if ($group === null) {
            return;
        }
        $old = (string) $group->name;
        $group->forceFill(['name' => RemovableIngredients::freeGroupName($companyId, $ownerProductId, $name)])->save();
        Product::query()->whereKey($ownerProductId)->update(['updated_at' => now()]);
        $this->writeAuditLog->handle(new AuditLogData(
            event: 'catalogue.addon_group.updated',
            actorUserId: $actor->getKey(),
            companyId: $companyId,
            auditableType: AddOnGroup::class,
            auditableId: $group->id,
            oldValues: ['name' => $old],
            newValues: ['name' => $group->name, 'reason' => 'name given to another of the product\'s own groups'],
        ));
    }

    /**
     * @return list<array{ingredient_id: int, name: string, name_ar: string|null, price_delta: string, takes_stock: bool}>
     */
    private function snapshot(Product $product): array
    {
        $group = RemovableIngredients::group($product);
        if ($group === null) {
            return [];
        }

        return AddOn::query()
            ->where('add_on_group_id', $group->id)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (AddOn $o): array => [
                'ingredient_id' => (int) $o->removes_ingredient_id,
                'name' => (string) $o->name,
                'name_ar' => $o->name_ar,
                // Fix order 1 (C-1) — a price change alone moves the group and
                // the product (devices re-read them) and is audited.
                'price_delta' => number_format((float) $o->price_delta, 3, '.', ''),
                'takes_stock' => AddOnKindRules::anyTakesStock([$o]),
            ])
            ->values()
            ->all();
    }
}
