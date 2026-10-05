<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Validation\Validator;

/**
 * LAUNCH review add-on (owner decision D12, tester calls 1 and 16) — "Can be
 * removed" on a recipe line.
 *
 * A product's removable ingredients live in ONE add-on group it owns, of kind
 * 'remove' ("Remove" / "إزالة", several may be picked, never required). Each
 * ticked recipe line is one price-0 option named "NO {label}" /
 * "بدون {label_ar}" (the label defaults to the ingredient's name) whose
 * removes_ingredient_id is the line's ingredient. Today's tills, handhelds
 * and the QR menu show, send and print it as an ordinary add-on; pos_api
 * leaves the ingredient out of the line's recipe copy, so it is not taken
 * from stock (made-to-order products only: for cooked, ready or untracked
 * products it can only print).
 *
 * The group is managed only from the recipe step: the generic add-on
 * endpoints refuse to edit it.
 */
final class RemovableIngredients
{
    public const GROUP_NAME = 'Remove';

    public const GROUP_NAME_AR = 'إزالة';

    public const PREFIX = 'NO ';

    public const PREFIX_AR = 'بدون ';

    public const LABEL_MAX = 60;

    /**
     * @return array<string, list<string>>
     */
    public static function rules(string $key): array
    {
        return [
            $key => ['sometimes', 'nullable', 'array', 'max:50'],
            $key.'.*.ingredient_uuid' => ['required', 'string', 'uuid'],
            $key.'.*.label' => ['nullable', 'string', 'max:'.self::LABEL_MAX],
            $key.'.*.label_ar' => ['nullable', 'string', 'max:'.self::LABEL_MAX],
        ];
    }

    /**
     * Each ticked line once, and only a line of this recipe.
     *
     * @param  list<string>  $recipeUuids
     */
    public static function checkAgainstRecipe(Validator $v, string $key, mixed $lines, array $recipeUuids): void
    {
        if (! is_array($lines)) {
            return;
        }
        $seen = [];
        foreach ($lines as $i => $line) {
            $uuid = is_array($line) ? (string) ($line['ingredient_uuid'] ?? '') : '';
            if ($uuid === '' || $v->errors()->has("$key.$i.ingredient_uuid")) {
                continue;
            }
            if (isset($seen[$uuid])) {
                $v->errors()->add("$key.$i.ingredient_uuid", 'This ingredient is ticked twice.');
            } elseif (! in_array($uuid, $recipeUuids, true)) {
                $v->errors()->add("$key.$i.ingredient_uuid", 'Only an ingredient of this recipe can be marked "Can be removed".');
            }
            $seen[$uuid] = true;
        }
    }

    /** The product's Remove group (soft-deleted too when $withTrashed). */
    public static function group(Product $product, bool $withTrashed = false): ?AddOnGroup
    {
        return AddOnGroup::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->where('company_id', $product->company_id)
            ->where('owner_product_id', $product->id)
            ->where('kind', AddOnGroup::KIND_REMOVE)
            ->orderBy('id')
            ->first();
    }

    /** "NO Ketchup" / "بدون كاتشب": the label, else the ingredient's name. */
    public static function optionName(Ingredient $ingredient, ?string $label): string
    {
        $text = trim((string) $label);
        if ($text === '') {
            $text = mb_substr(trim((string) $ingredient->name), 0, self::LABEL_MAX);
        }

        return self::PREFIX.$text;
    }

    public static function optionNameAr(Ingredient $ingredient, ?string $labelAr, ?string $label): string
    {
        $text = trim((string) $labelAr);
        if ($text === '') {
            $text = trim((string) $ingredient->name_ar);
        }
        if ($text === '') {
            $text = trim((string) $label);
        }
        if ($text === '') {
            $text = trim((string) $ingredient->name);
        }

        return self::PREFIX_AR.mb_substr($text, 0, self::LABEL_MAX);
    }

    /** The label part of an option name ("NO Ketchup" → "Ketchup"). */
    public static function labelOf(?string $name, string $prefix): ?string
    {
        if ($name === null) {
            return null;
        }

        return str_starts_with($name, $prefix) ? mb_substr($name, mb_strlen($prefix)) : $name;
    }

    /**
     * What the recipe step shows: one row per ticked line.
     *
     * @return array{applies_to_stock: bool, lines: list<array{ingredient_uuid: string, ingredient_name: string|null, addon_uuid: string, name: string, name_ar: string|null, label: string|null, label_ar: string|null}>}
     */
    public static function state(Product $product): array
    {
        $group = self::group($product);
        $lines = [];
        if ($group !== null) {
            $options = AddOn::query()
                ->where('add_on_group_id', $group->id)
                ->whereNotNull('removes_ingredient_id')
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();
            $ingredients = Ingredient::query()
                ->withTrashed()
                ->where('company_id', $product->company_id)
                ->whereIn('id', $options->pluck('removes_ingredient_id')->all())
                ->get()
                ->keyBy('id');
            foreach ($options as $option) {
                $ingredient = $ingredients->get((int) $option->removes_ingredient_id);
                if ($ingredient === null) {
                    continue;
                }
                $lines[] = [
                    'ingredient_uuid' => (string) $ingredient->uuid,
                    'ingredient_name' => $ingredient->name,
                    'addon_uuid' => (string) $option->uuid,
                    'name' => (string) $option->name,
                    'name_ar' => $option->name_ar,
                    'label' => self::labelOf((string) $option->name, self::PREFIX),
                    'label_ar' => self::labelOf($option->name_ar, self::PREFIX_AR),
                ];
            }
        }

        return [
            // Tester call 16 — only a made-to-order product copies its recipe
            // at sale, so only there does a removal keep the ingredient in
            // stock; for the others it only prints.
            'applies_to_stock' => self::appliesToStock($product),
            'lines' => $lines,
        ];
    }

    /**
     * Fix order C-1, L2 — the first final option name (EN or AR, any case)
     * that two ticked lines share, or null.
     *
     * @param  array<int|string, mixed>  $lines
     * @param  array<string, Ingredient>  $ingredientsByUuid
     * @return array{index: int|string, field: 'label'|'label_ar', name: string}|null
     */
    public static function duplicateName(array $lines, array $ingredientsByUuid): ?array
    {
        $seen = ['label' => [], 'label_ar' => []];
        foreach ($lines as $i => $line) {
            $ingredient = is_array($line) ? ($ingredientsByUuid[(string) ($line['ingredient_uuid'] ?? '')] ?? null) : null;
            if (! $ingredient instanceof Ingredient) {
                continue;
            }
            $names = [
                'label' => self::optionName($ingredient, $line['label'] ?? null),
                'label_ar' => self::optionNameAr($ingredient, $line['label_ar'] ?? null, $line['label'] ?? null),
            ];
            foreach ($names as $field => $name) {
                $key = mb_strtolower($name);
                if (isset($seen[$field][$key])) {
                    return ['index' => $i, 'field' => $field, 'name' => $name];
                }
                $seen[$field][$key] = true;
            }
        }

        return null;
    }

    public static function duplicateMessage(string $name): string
    {
        return sprintf('Two ticked lines would both show "%s" to customers: give one of them another label.', $name);
    }

    /**
     * Fix order C-1, L2 — refuse two ticked lines with the same final name, on
     * the line and field ('label' / 'label_ar') that repeats it.
     */
    public static function checkNames(Validator $v, string $key, mixed $lines, int $companyId): void
    {
        if (! is_array($lines) || $lines === []) {
            return;
        }
        $uuids = [];
        foreach ($lines as $line) {
            if (is_array($line) && is_string($line['ingredient_uuid'] ?? null)) {
                $uuids[] = $line['ingredient_uuid'];
            }
        }
        $ingredients = Ingredient::query()->where('company_id', $companyId)->whereIn('uuid', $uuids)->get()->keyBy('uuid')->all();
        $duplicate = self::duplicateName($lines, $ingredients);
        if ($duplicate !== null && ! $v->errors()->has("$key.{$duplicate['index']}.{$duplicate['field']}")) {
            $v->errors()->add("$key.{$duplicate['index']}.{$duplicate['field']}", self::duplicateMessage($duplicate['name']));
        }
    }

    /**
     * Fix order C-1, M1 — are the ticks a page loaded ($expected: ingredient
     * uuid + label + Arabic label) still the saved ones? Compared on the
     * lines of the recipe as it is now: a line this same save dropped from
     * the recipe has already lost its option, which is not a conflict.
     *
     * @param  array<int, mixed>  $expected
     */
    public static function matchesSaved(Product $product, array $expected): bool
    {
        $inRecipe = Ingredient::query()
            ->withTrashed()
            ->where('company_id', $product->company_id)
            ->whereIn('id', $product->recipeLines()->pluck('ingredient_id')->all())
            ->pluck('uuid')
            ->map(fn ($uuid): string => (string) $uuid)
            ->all();

        $comparable = static function (iterable $lines) use ($inRecipe): array {
            $out = [];
            foreach ($lines as $line) {
                $uuid = is_array($line) ? (string) ($line['ingredient_uuid'] ?? '') : '';
                if ($uuid === '' || ! in_array($uuid, $inRecipe, true)) {
                    continue;
                }
                $out[$uuid] = [trim((string) ($line['label'] ?? '')), trim((string) ($line['label_ar'] ?? ''))];
            }
            ksort($out);

            return $out;
        };

        return $comparable(self::state($product)['lines']) === $comparable($expected);
    }

    /**
     * A name for the Remove list that no other group of this product holds,
     * soft-deleted ones included (the database index's scope, whatever the
     * kind): "Remove", else "Remove 2", "Remove 3"…
     */
    public static function freeGroupName(int $companyId, int $ownerProductId, ?string $avoid = null): string
    {
        $used = AddOnGroup::query()->withTrashed()
            ->where('company_id', $companyId)
            ->where('owner_product_id', $ownerProductId)
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->all();
        if ($avoid !== null) {
            $used[] = $avoid;
        }
        $name = self::GROUP_NAME;
        for ($n = 2; in_array($name, $used, true); $n++) {
            $name = self::GROUP_NAME.' '.$n;
        }

        return $name;
    }

    public static function appliesToStock(Product $product): bool
    {
        $mode = $product->stock_mode instanceof \BackedEnum ? $product->stock_mode->value : (string) $product->stock_mode;

        return $mode === 'ingredient';
    }
}
