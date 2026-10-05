<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\ProductStatus;
use App\Models\ComboSlot;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\BranchScope;
use App\Support\Catalogue\MenuExtras;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * LAUNCH-P4 B2 — validates POST /api/combos and PUT /api/combos/{uuid}.
 *
 * A combo (owner decision 7) is a product with a set price plus choice slots;
 * each slot offers items (standard products of the same company), each with
 * an extra price (same on every channel) and an optional default. The combo's
 * own price follows the channel rules like any product (base / delivery /
 * per provider). It keeps no stock, recipe or components of its own.
 */
class SaveComboRequest extends FormRequest
{
    /** A branch payload is HQ-only (P-G5), refused before validation. */
    public function authorize(): bool
    {
        if ($this->input('branches') !== null) {
            BranchScope::ensureUnrestricted(
                $this->user(),
                'Branch availability is managed by accounts with access to all branches.',
            );
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->route('product') !== null;

        return [
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:999999.999', 'decimal:0,3'],
            'delivery_price' => ['nullable', 'numeric', 'min:0', 'max:999999.999', 'decimal:0,3'],
            'sold_in_store' => ['required', 'boolean'],
            'show_on_customer_tablet' => ['required', 'boolean'],
            'sold_on_delivery' => ['required', 'boolean'],
            'available_from' => ['nullable', 'string', 'regex:/^[0-2]\d:[0-5]\d(:[0-5]\d)?$/'],
            'available_until' => ['nullable', 'string', 'regex:/^[0-2]\d:[0-5]\d(:[0-5]\d)?$/'],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
            'status' => [$isUpdate ? 'sometimes' : 'prohibited', 'string', Rule::in(ProductStatus::values())],
            // LAUNCH review add-on — limited-time dates and the combo's own
            // cooking time (else the menu shows its longest item's).
            ...MenuExtras::productRules(),

            'slots' => ['required', 'array', 'min:1', 'max:10'],
            'slots.*.id' => ['nullable', 'integer'],
            'slots.*.name' => ['required', 'string', 'max:64'],
            'slots.*.name_ar' => ['nullable', 'string', 'max:64'],
            'slots.*.min_choices' => ['required', 'integer', 'between:0,20'],
            'slots.*.max_choices' => ['required', 'integer', 'between:1,20'],
            'slots.*.options' => ['required', 'array', 'min:1', 'max:50'],
            'slots.*.options.*.product_uuid' => ['required', 'string', 'uuid'],
            'slots.*.options.*.extra_price' => ['nullable', 'numeric', 'min:0', 'max:999.999', 'decimal:0,3'],
            'slots.*.options.*.is_default' => ['nullable', 'boolean'],
            // LAUNCH review add-on — the slot offered as "Make it a meal?".
            'slots.*.is_main' => ['nullable', 'boolean'],

            // Channels per provider (B3) and the branch rule (H6).
            'delivery_prices' => ['present', 'array', 'max:50'],
            'delivery_prices.*.provider_uuid' => ['required', 'string', 'uuid'],
            'delivery_prices.*.listed' => ['nullable', 'boolean'],
            'delivery_prices.*.price' => ['nullable', 'numeric', 'gt:0', 'max:999999.999'],
            'branches' => ['nullable', 'array'],
            'branches.branch_scope' => ['required_with:branches', 'string', Rule::in([Product::SCOPE_ALL, Product::SCOPE_SELECTED])],
            'branches.branch_ids' => ['nullable', 'array', 'max:500'],
            'branches.branch_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null || $v->errors()->isNotEmpty()) {
                return;
            }
            /** @var Product|null $combo */
            $combo = $this->route('product');

            $this->checkBasics($v, $companyId, $combo);
            $this->checkSlots($v, $companyId, $combo);

            if ($this->input('branches.branch_scope') === Product::SCOPE_SELECTED && empty($this->input('branches.branch_ids'))) {
                $v->errors()->add('branches.branch_ids', 'Pick at least one branch, or choose all branches.');
            }
        });
    }

    private function checkBasics(Validator $v, int $companyId, ?Product $combo): void
    {
        $categoryId = $this->input('category_id');
        if ($categoryId !== null && $categoryId !== '') {
            $owned = ProductCategory::query()->where('id', (int) $categoryId)->where('company_id', $companyId)->exists();
            if (! $owned) {
                $v->errors()->add('category_id', 'The selected category does not belong to your company.');
            }
        }
        foreach (['sku' => 'SKU', 'barcode' => 'barcode'] as $field => $label) {
            $value = $this->input($field);
            if (is_string($value) && $value !== '') {
                $taken = Product::query()
                    ->where('company_id', $companyId)
                    ->where($field, $value)
                    ->when($combo !== null, fn ($q) => $q->where('id', '!=', $combo->id))
                    ->exists();
                if ($taken) {
                    $v->errors()->add($field, "A product with this {$label} already exists at your company.");
                }
            }
        }
    }

    /**
     * Each slot: max >= min; its options are standard, sellable products of
     * this company, each at most once, with no more defaults than the slot
     * allows; a slot id (update) must belong to this combo.
     */
    private function checkSlots(Validator $v, int $companyId, ?Product $combo): void
    {
        $slots = (array) $this->input('slots', []);
        $uuids = [];
        foreach ($slots as $slot) {
            foreach ((array) ($slot['options'] ?? []) as $option) {
                $uuids[] = (string) ($option['product_uuid'] ?? '');
            }
        }
        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', array_values(array_unique($uuids)))
            ->get(['id', 'uuid', 'product_type', 'is_internal'])
            ->keyBy('uuid');
        $ownSlotIds = $combo === null ? [] : ComboSlot::query()
            ->where('combo_product_id', $combo->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($slots as $i => $slot) {
            $min = (int) ($slot['min_choices'] ?? 0);
            $max = (int) ($slot['max_choices'] ?? 1);
            if ($max < $min) {
                $v->errors()->add("slots.$i.max_choices", 'The most an item can be chosen must not be below the least.');
            }
            if (isset($slot['id']) && $slot['id'] !== null && ! in_array((int) $slot['id'], $ownSlotIds, true)) {
                $v->errors()->add("slots.$i.id", 'This slot does not belong to this combo.');
            }

            $seen = [];
            $defaults = 0;
            foreach ((array) ($slot['options'] ?? []) as $j => $option) {
                $uuid = (string) ($option['product_uuid'] ?? '');
                $product = $products->get($uuid);
                if ($product === null) {
                    $v->errors()->add("slots.$i.options.$j.product_uuid", 'This item does not belong to your company.');

                    continue;
                }
                if ($product->is_internal || $product->product_type !== Product::TYPE_STANDARD) {
                    $v->errors()->add("slots.$i.options.$j.product_uuid", 'A combo item must be a product on the menu (not a combo or a physical item).');
                }
                if ($combo !== null && (int) $product->id === (int) $combo->id) {
                    $v->errors()->add("slots.$i.options.$j.product_uuid", 'A combo cannot contain itself.');
                }
                if (isset($seen[$uuid])) {
                    $v->errors()->add("slots.$i.options.$j.product_uuid", 'This item is already in the slot.');
                }
                $seen[$uuid] = true;
                if (! empty($option['is_default'])) {
                    $defaults++;
                }
            }
            if ($defaults > $max) {
                $v->errors()->add("slots.$i.options", 'More items are marked as default than the slot allows.');
            }
        }
    }

    /**
     * LAUNCH review add-on — the dates ("Until" on or after "From") and the
     * main slot (tester call 15): at most one per combo, and only a slot
     * where exactly one item is picked (min = max = 1), so "Make it a meal?"
     * can pre-pick the tapped item. "Burgers, pick 4" is never a main.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $v): void {
            MenuExtras::checkDates($v, $this->input('on_sale_from'), $this->input('on_sale_until'), 'on_sale_until');

            $mains = 0;
            foreach ((array) $this->input('slots', []) as $i => $slot) {
                if (! is_array($slot) || ! filter_var($slot['is_main'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $mains++;
                if ($mains > 1) {
                    $v->errors()->add("slots.$i.is_main", 'Only one slot can be the main item.');
                }
                if ((int) ($slot['min_choices'] ?? 0) !== 1 || (int) ($slot['max_choices'] ?? 0) !== 1) {
                    $v->errors()->add("slots.$i.is_main", 'The main item must be a slot where exactly one item is picked (least 1, most 1).');
                }
            }
        }];
    }
}
