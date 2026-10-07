<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\ComboLine;
use App\Models\ComboLineItem;
use App\Models\ComboLineUpgrade;
use App\Models\Meal;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;
use RuntimeException;

/**
 * LAUNCH combo add-on (LAUNCH-COMBO_WORK_ORDER.md §1.2) — the lines of a
 * combo or a meal, as the portal saves and shows them. One shape for both:
 *
 *   {id?, kind: 'fixed', product_uuid, quantity (1..99),
 *    upgrades: [{product_uuid, upgrade_price}]}          "Included item"
 *   {id?, kind: 'choice', name, name_ar, category_id, pick_count (1..20),
 *    items: [{product_uuid, excluded, extra_price}]}      "Choice"
 *
 * Every product is a standard, non-internal product of the merchant (never
 * a combo, never the combo itself); every category is the merchant's; a
 * choice override names a product of that category. Lines keep their ids
 * across edits (order lines and device carts refer to them); upgrades and
 * overrides are rewritten. Only overrides that change something are stored
 * (unticked, or an extra price above 0): a category product without a row
 * is in and free, so new products join automatically.
 */
final class ComboLinesInput
{
    /** @return array<string, mixed> */
    public static function rules(string $key = 'lines'): array
    {
        return [
            $key => ['required', 'array', 'min:1', 'max:20'],
            $key.'.*.id' => ['nullable', 'integer'],
            $key.'.*.kind' => ['required', 'string', 'in:fixed,choice'],
            $key.'.*.product_uuid' => ['required_if:'.$key.'.*.kind,fixed', 'nullable', 'string', 'uuid'],
            $key.'.*.quantity' => ['required_if:'.$key.'.*.kind,fixed', 'nullable', 'integer', 'between:1,99'],
            $key.'.*.upgrades' => ['sometimes', 'nullable', 'array', 'max:20'],
            $key.'.*.upgrades.*.product_uuid' => ['required', 'string', 'uuid'],
            $key.'.*.upgrades.*.upgrade_price' => ['nullable', 'numeric', 'min:0', 'max:999.999', 'decimal:0,3'],
            $key.'.*.name' => ['required_if:'.$key.'.*.kind,choice', 'nullable', 'string', 'max:64'],
            $key.'.*.name_ar' => ['nullable', 'string', 'max:64'],
            $key.'.*.category_id' => ['required_if:'.$key.'.*.kind,choice', 'nullable', 'integer', 'min:1'],
            $key.'.*.pick_count' => ['required_if:'.$key.'.*.kind,choice', 'nullable', 'integer', 'between:1,20'],
            $key.'.*.items' => ['sometimes', 'nullable', 'array', 'max:300'],
            $key.'.*.items.*.product_uuid' => ['required', 'string', 'uuid'],
            $key.'.*.items.*.excluded' => ['nullable', 'boolean'],
            $key.'.*.items.*.extra_price' => ['nullable', 'numeric', 'min:0', 'max:999.999', 'decimal:0,3'],
        ];
    }

    /**
     * Tenancy and the line rules (after the field rules passed).
     *
     * @param  array{combo_product_id?: int, meal_id?: int}  $owner  the saved owner (update), or [] (create)
     */
    public static function check(Validator $v, int $companyId, array $owner, mixed $lines, ?int $selfProductId = null, string $key = 'lines'): void
    {
        $lines = is_array($lines) ? array_values($lines) : [];
        $uuids = [];
        $categoryIds = [];
        foreach ($lines as $line) {
            $uuids[] = (string) ($line['product_uuid'] ?? '');
            foreach ((array) ($line['upgrades'] ?? []) as $upgrade) {
                $uuids[] = (string) ($upgrade['product_uuid'] ?? '');
            }
            foreach ((array) ($line['items'] ?? []) as $item) {
                $uuids[] = (string) ($item['product_uuid'] ?? '');
            }
            if (isset($line['category_id'])) {
                $categoryIds[] = (int) $line['category_id'];
            }
        }
        $products = Product::query()->where('company_id', $companyId)
            ->whereIn('uuid', array_values(array_unique(array_filter($uuids))))
            ->get(['id', 'uuid', 'name', 'product_type', 'is_internal', 'category_id'])->keyBy('uuid');
        $categories = ProductCategory::query()->where('company_id', $companyId)
            ->whereIn('id', array_values(array_unique($categoryIds)) ?: [0])->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $ownIds = $owner === [] ? [] : ComboLine::query()->where($owner)->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $menuItem = function (string $field, string $uuid) use ($v, $products, $selfProductId): ?Product {
            $product = $products->get($uuid);
            if ($product === null) {
                $v->errors()->add($field, 'This item does not belong to your company.');

                return null;
            }
            if ((bool) $product->is_internal || $product->product_type !== Product::TYPE_STANDARD) {
                $v->errors()->add($field, 'An item of a combo or meal must be a product on the menu (not a combo or a physical item).');

                return null;
            }
            if ($selfProductId !== null && (int) $product->id === $selfProductId) {
                $v->errors()->add($field, 'A combo cannot contain itself.');

                return null;
            }

            return $product;
        };

        foreach ($lines as $i => $line) {
            if (isset($line['id']) && $line['id'] !== null && ! in_array((int) $line['id'], $ownIds, true)) {
                $v->errors()->add("$key.$i.id", 'This line does not belong to this combo or meal.');
            }
            if (($line['kind'] ?? null) === ComboLine::FIXED) {
                $item = $menuItem("$key.$i.product_uuid", (string) ($line['product_uuid'] ?? ''));
                $seen = [];
                foreach ((array) ($line['upgrades'] ?? []) as $j => $upgrade) {
                    $uuid = (string) ($upgrade['product_uuid'] ?? '');
                    $menuItem("$key.$i.upgrades.$j.product_uuid", $uuid);
                    if ($item !== null && $uuid === $item->uuid) {
                        $v->errors()->add("$key.$i.upgrades.$j.product_uuid", 'An upgrade must be another product than the included item.');
                    }
                    if (isset($seen[$uuid])) {
                        $v->errors()->add("$key.$i.upgrades.$j.product_uuid", 'This upgrade is listed twice.');
                    }
                    $seen[$uuid] = true;
                }

                continue;
            }
            $categoryId = (int) ($line['category_id'] ?? 0);
            if (! in_array($categoryId, $categories, true)) {
                $v->errors()->add("$key.$i.category_id", 'The selected category does not belong to your company.');

                continue;
            }
            $seen = [];
            $excluded = [];
            foreach ((array) ($line['items'] ?? []) as $j => $row) {
                $uuid = (string) ($row['product_uuid'] ?? '');
                $product = $products->get($uuid);
                if ($product === null || (int) $product->category_id !== $categoryId) {
                    $v->errors()->add("$key.$i.items.$j.product_uuid", 'This item is not in the chosen category.');

                    continue;
                }
                if (isset($seen[$uuid])) {
                    $v->errors()->add("$key.$i.items.$j.product_uuid", 'This item is listed twice.');
                }
                $seen[$uuid] = true;
                if (! empty($row['excluded'])) {
                    $excluded[] = (int) $product->id;
                }
            }
            $left = Product::query()->where('company_id', $companyId)->where('category_id', $categoryId)
                ->where('product_type', Product::TYPE_STANDARD)->where('is_internal', false)
                ->when($selfProductId !== null, static fn ($q) => $q->where('id', '<>', $selfProductId))
                ->whereNotIn('id', $excluded ?: [0])->exists();
            if (! $left) {
                $v->errors()->add("$key.$i.items", 'No item is left to choose in this category: tick at least one.');
            }
        }
    }

    /**
     * Write the lines of $owner (['combo_product_id' => id] or ['meal_id' =>
     * id]) in the order sent; a line sent with its id keeps it.
     *
     * @param  array{combo_product_id?: int, meal_id?: int}  $owner
     * @param  list<array<string, mixed>>  $lines
     */
    public static function save(array $owner, array $lines, int $companyId): void
    {
        $uuids = [];
        foreach ($lines as $line) {
            $uuids[] = (string) ($line['product_uuid'] ?? '');
            foreach ((array) ($line['upgrades'] ?? []) as $upgrade) {
                $uuids[] = (string) $upgrade['product_uuid'];
            }
            foreach ((array) ($line['items'] ?? []) as $item) {
                $uuids[] = (string) $item['product_uuid'];
            }
        }
        $ids = Product::query()->where('company_id', $companyId)->whereIn('uuid', array_values(array_unique(array_filter($uuids))))->pluck('id', 'uuid');
        $id = static function (string $uuid) use ($ids): int {
            $productId = (int) ($ids[$uuid] ?? 0);
            if ($productId === 0) {
                throw new RuntimeException('An item of the combo or meal does not belong to your company.');
            }

            return $productId;
        };

        $existing = ComboLine::query()->where($owner)->get()->keyBy('id');
        $keep = [];
        foreach (array_values($lines) as $sort => $data) {
            $fixed = $data['kind'] === ComboLine::FIXED;
            $attributes = [
                'kind' => $data['kind'],
                'product_id' => $fixed ? $id((string) $data['product_uuid']) : null,
                'quantity' => $fixed ? (int) $data['quantity'] : null,
                'category_id' => $fixed ? null : (int) $data['category_id'],
                'pick_count' => $fixed ? null : (int) $data['pick_count'],
                'name' => $fixed ? null : trim((string) $data['name']),
                'name_ar' => $fixed || trim((string) ($data['name_ar'] ?? '')) === '' ? null : trim((string) $data['name_ar']),
                'sort_order' => $sort,
            ];
            $line = isset($data['id']) ? $existing->get((int) $data['id']) : null;
            if ($line === null) {
                $line = ComboLine::query()->create($attributes + $owner + ['company_id' => $companyId]);
            } else {
                $line->forceFill($attributes)->save();
            }
            $keep[] = (int) $line->id;

            ComboLineUpgrade::query()->where('line_id', $line->id)->delete();
            ComboLineItem::query()->where('line_id', $line->id)->delete();
            if ($fixed) {
                foreach (array_values((array) ($data['upgrades'] ?? [])) as $order => $upgrade) {
                    ComboLineUpgrade::query()->create(['company_id' => $companyId, 'line_id' => $line->id,
                        'product_id' => $id((string) $upgrade['product_uuid']), 'sort_order' => $order,
                        'upgrade_price' => number_format((float) ($upgrade['upgrade_price'] ?? 0), 3, '.', '')]);
                }

                continue;
            }
            foreach ((array) ($data['items'] ?? []) as $item) {
                $extra = round((float) ($item['extra_price'] ?? 0), 3);
                $excluded = ! empty($item['excluded']);
                if (! $excluded && $extra <= 0) {
                    continue;
                }
                ComboLineItem::query()->create(['company_id' => $companyId, 'line_id' => $line->id,
                    'product_id' => $id((string) $item['product_uuid']), 'excluded' => $excluded,
                    'extra_price' => number_format($extra, 3, '.', '')]);
            }
        }
        ComboLine::query()->where($owner)->whereNotIn('id', $keep === [] ? [0] : $keep)->delete();
    }

    /**
     * The lines as the portal shows them (and the audit compares them).
     *
     * @param  array{combo_product_id?: int, meal_id?: int}  $owner
     * @return list<array<string, mixed>>
     */
    public static function present(array $owner): array
    {
        $lines = ComboLine::query()->where($owner)->with(['product', 'category', 'upgrades.product', 'items.product'])
            ->orderBy('sort_order')->orderBy('id')->get();

        return $lines->map(static fn (ComboLine $line): array => [
            'id' => (int) $line->id,
            'kind' => (string) $line->kind,
            'sort_order' => (int) $line->sort_order,
            'product_uuid' => $line->product?->uuid,
            'product_name' => $line->product?->name,
            'product_name_ar' => $line->product?->name_ar,
            'product_base_price' => $line->product !== null ? (string) $line->product->base_price : null,
            'product_available' => self::available($line->product),
            'quantity' => $line->quantity,
            'upgrades' => $line->upgrades->map(static fn (ComboLineUpgrade $u): array => [
                'product_uuid' => $u->product?->uuid,
                'product_name' => $u->product?->name,
                'product_name_ar' => $u->product?->name_ar,
                'product_base_price' => $u->product !== null ? (string) $u->product->base_price : null,
                'product_available' => self::available($u->product),
                'upgrade_price' => (string) $u->upgrade_price,
                'sort_order' => (int) $u->sort_order,
            ])->values()->all(),
            'name' => $line->name,
            'name_ar' => $line->name_ar,
            'category_id' => $line->category_id !== null ? (int) $line->category_id : null,
            'category_name' => $line->category?->name,
            'pick_count' => $line->pick_count,
            'items' => $line->items->map(static fn (ComboLineItem $i): array => [
                'product_uuid' => $i->product?->uuid,
                'product_name' => $i->product?->name,
                'excluded' => (bool) $i->excluded,
                'extra_price' => (string) $i->extra_price,
            ])->values()->all(),
        ])->values()->all();
    }

    /** A product used by a combo or meal line (included item or upgrade) cannot be deleted. */
    public static function refuseProductInUse(Product $product): void
    {
        $lineIds = ComboLine::query()->where('product_id', $product->id)->pluck('id')
            ->merge(ComboLineUpgrade::query()->where('product_id', $product->id)->pluck('line_id'))->unique()->all();
        $names = self::ownerNames($lineIds);
        if ($names !== []) {
            throw new RuntimeException('This item is in a combo or meal: remove it from '.implode(', ', $names).' first. / هذا الصنف داخل كومبو أو وجبة: أزله منها أولاً.');
        }
    }

    /** A category a combo or meal chooses from, or a meal's mains category, stays while used. */
    public static function refuseCategoryInUse(ProductCategory $category): void
    {
        $names = self::ownerNames(ComboLine::query()->where('category_id', $category->id)->pluck('id')->all());
        $meals = Meal::query()->whereIn('id', DB::table('pos_meal_categories')->where('category_id', $category->id)->pluck('meal_id')->all() ?: [0])
            ->orderBy('name')->pluck('name')->all();
        $names = array_values(array_unique(array_merge($names, $meals)));
        if ($names !== []) {
            throw new RuntimeException('This category is used by '.implode(', ', $names).': change them first. / هذا القسم مستخدم في كومبو أو وجبة: عدّلها أولاً.');
        }
    }

    /**
     * @param  list<int>  $lineIds
     * @return list<string>
     */
    private static function ownerNames(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }
        $lines = ComboLine::query()->whereIn('id', $lineIds)->get(['combo_product_id', 'meal_id']);
        $combos = Product::query()->whereIn('id', $lines->pluck('combo_product_id')->filter()->all() ?: [0])->orderBy('name')->pluck('name');
        $meals = Meal::query()->whereIn('id', $lines->pluck('meal_id')->filter()->all() ?: [0])->orderBy('name')->pluck('name');

        return array_values(array_unique($combos->merge($meals)->all()));
    }

    private static function available(?Product $product): bool
    {
        return $product !== null && ! $product->trashed() && ($product->status?->value ?? 'active') === 'active';
    }
}
