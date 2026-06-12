<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\AddOnSelectionMode;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PD1 — validates POST /api/products/wizard: the 3-step product wizard's
 * single ATOMIC submit (product + shared-group attachments + inline
 * product-owned add-on groups with their options + recipe + physical
 * items + branch availability + delivery-provider prices). The legacy
 * per-section endpoints stay for edit mode; this request exists so a
 * NEW product either lands fully configured or not at all.
 *
 * The product.* rules are CreateProductRequest's rules verbatim
 * (composed, not copied) so the two create paths can never drift.
 */
class CreateProductWizardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = [];
        foreach ((new CreateProductRequest)->rules() as $field => $rule) {
            $product['product.'.$field] = $rule;
        }

        return $product + [
            'product' => ['required', 'array'],

            // Shared (company-wide) groups to attach, by uuid.
            'addon_group_uuids' => ['present', 'array', 'max:50'],
            'addon_group_uuids.*' => ['string', 'uuid'],

            // Inline product-owned groups — created WITH the product
            // (the old modal forced save-first because owner_product_id
            // needs a persisted product id; the wizard buffers instead).
            'owned_groups' => ['present', 'array', 'max:20'],
            'owned_groups.*.name' => ['required', 'string', 'max:100'],
            'owned_groups.*.name_ar' => ['nullable', 'string', 'max:100'],
            'owned_groups.*.selection_mode' => ['nullable', 'string', 'in:single,multi'],
            'owned_groups.*.min_selections' => ['nullable', 'integer', 'between:0,99'],
            'owned_groups.*.max_selections' => ['nullable', 'integer', 'between:1,99'],
            'owned_groups.*.display_order' => ['nullable', 'integer', 'between:0,999'],
            'owned_groups.*.options' => ['present', 'array', 'max:50'],
            'owned_groups.*.options.*.name' => ['required', 'string', 'max:100'],
            'owned_groups.*.options.*.name_ar' => ['nullable', 'string', 'max:100'],
            'owned_groups.*.options.*.price_delta' => ['nullable', 'numeric', 'min:0', 'max:999.999'],
            'owned_groups.*.options.*.is_default' => ['nullable', 'boolean'],
            'owned_groups.*.options.*.linked_product_uuid' => ['nullable', 'string', 'uuid'],
            'owned_groups.*.options.*.display_order' => ['nullable', 'integer', 'between:0,999'],

            // Recipe — only meaningful for made-to-order + cooked
            // (cross-checked against product.stock_mode below).
            'recipe_lines' => ['present', 'array', 'max:50'],
            'recipe_lines.*.ingredient_uuid' => ['required', 'string', 'uuid'],
            'recipe_lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'recipe_lines.*.unit' => ['nullable', 'string', 'max:32'],
            'recipe_note' => ['nullable', 'string', 'max:1000'],

            // Physical items (P-G2) — same shape as the standalone PUT.
            'component_lines' => ['present', 'array', 'max:50'],
            'component_lines.*.component_uuid' => ['required', 'string', 'uuid'],
            'component_lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999.999'],

            // Branch availability. NULL (or omitted) = skip the sync
            // entirely — available everywhere, and the only legal value
            // for branch-restricted users (the controller 403s a non-null
            // payload from them, mirroring the standalone PUT).
            'branches' => ['nullable', 'array'],
            'branches.*.branch_id' => ['required', 'integer'],
            'branches.*.is_available' => ['required', 'boolean'],
            'branches.*.stock_qty' => ['nullable', 'numeric', 'min:0'],

            // Delivery-provider price overrides.
            'delivery_prices' => ['present', 'array', 'max:50'],
            'delivery_prices.*.provider_uuid' => ['required', 'string', 'uuid'],
            'delivery_prices.*.price' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            $this->checkProductBasics($v, $companyId);
            $this->checkRecipeStockMode($v);
            $this->checkOwnedGroups($v, $companyId);
        });
    }

    /**
     * The same tenant/uniqueness checks CreateProductRequest runs,
     * re-rooted at product.* so the field-level 422s land on the
     * wizard's nested paths.
     */
    private function checkProductBasics(Validator $v, int $companyId): void
    {
        $categoryId = $this->input('product.category_id');
        if ($categoryId !== null && $categoryId !== '') {
            $categoryOwned = ProductCategory::query()
                ->where('id', (int) $categoryId)
                ->where('company_id', $companyId)
                ->exists();
            if (! $categoryOwned) {
                $v->errors()->add('product.category_id', 'The selected category does not belong to your company.');
            }
        }

        $sku = $this->input('product.sku');
        if (is_string($sku) && $sku !== '') {
            $taken = Product::query()
                ->where('company_id', $companyId)
                ->where('sku', $sku)
                ->exists();
            if ($taken) {
                $v->errors()->add('product.sku', 'A product with this SKU already exists at your company.');
            }
        }

        $barcode = $this->input('product.barcode');
        if (is_string($barcode) && $barcode !== '') {
            $taken = Product::query()
                ->where('company_id', $companyId)
                ->where('barcode', $barcode)
                ->exists();
            if ($taken) {
                $v->errors()->add('product.barcode', 'A product with this barcode already exists at your company.');
            }
        }
    }

    /**
     * PD1 design rule: a recipe belongs to products whose ingredients
     * are consumed (made-to-order at sale, cooked at production). Ready
     * / bought-in and untracked products must not carry one.
     */
    private function checkRecipeStockMode(Validator $v): void
    {
        $lines = $this->input('recipe_lines');
        if (! is_array($lines) || $lines === []) {
            return;
        }

        $mode = (string) ($this->input('product.stock_mode') ?? 'untracked');
        if (! in_array($mode, ['ingredient', 'cooked'], true)) {
            $v->errors()->add('recipe_lines', 'Only made-to-order and cooked products can have a recipe.');
        }
    }

    /**
     * Owned-group names must be unique within the payload AND against
     * the company's existing groups (pos_addon_groups carries a hard
     * UNIQUE (company_id, name) with no owner carve-out) — checked here
     * so the user gets a per-group 422 instead of a mid-transaction DB
     * error. Min/max cross-checks mirror CreateAddOnGroupRequest.
     */
    private function checkOwnedGroups(Validator $v, int $companyId): void
    {
        $groups = $this->input('owned_groups');
        if (! is_array($groups) || $groups === []) {
            return;
        }

        $seen = [];
        foreach ($groups as $i => $group) {
            $name = trim((string) ($group['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $v->errors()->add("owned_groups.$i.name", 'This group name is used twice in the form.');
            }
            $seen[$key] = true;

            // withTrashed: the DB unique index has no deleted_at
            // carve-out, so a soft-deleted group still occupies the
            // name — without this the INSERT trips the index instead
            // of the user getting this clean per-group 422.
            $taken = AddOnGroup::query()
                ->withTrashed()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->exists();
            if ($taken) {
                $v->errors()->add("owned_groups.$i.name", 'An add-on group with this name already exists (it may belong to a deleted group).');
            }

            $min = $group['min_selections'] ?? null;
            $max = $group['max_selections'] ?? null;
            if ($min !== null && $max !== null && (int) $max < (int) $min) {
                $v->errors()->add("owned_groups.$i.max_selections", 'Maximum selections cannot be below the minimum.');
            }

            $mode = (string) ($group['selection_mode'] ?? AddOnSelectionMode::Single->value);
            if ($mode === AddOnSelectionMode::Single->value && $min !== null && (int) $min > 1) {
                $v->errors()->add("owned_groups.$i.min_selections", 'A single-choice group can require at most one selection.');
            }
        }
    }
}
