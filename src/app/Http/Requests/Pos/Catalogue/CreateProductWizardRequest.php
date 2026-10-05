<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\AddOnSelectionMode;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\BranchScope;
use App\Support\Catalogue\MenuExtras;
use App\Support\Catalogue\RemovableIngredients;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
     * LAUNCH-P4 H6 — a branch payload is HQ-only (P-G5); refused before
     * validation so a branch-restricted user always gets the 403.
     */
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
            // PD3b — per-option stock-usage lines, same rules as the
            // standalone option create (composed, not copied — the
            // wizard passes option payloads RAW into CreateAddOnAction,
            // so an unvalidated key would be silently stripped here
            // while working on the standalone path).
            ...CreateAddOnRequest::consumptionRules('owned_groups.*.options.*.consumption'),

            // Recipe — only meaningful for made-to-order + cooked
            // (cross-checked against product.stock_mode below).
            'recipe_lines' => ['present', 'array', 'max:50'],
            'recipe_lines.*.ingredient_uuid' => ['required', 'string', 'uuid'],
            'recipe_lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:999999.999'],
            'recipe_lines.*.unit' => ['nullable', 'string', 'max:32'],
            'recipe_note' => ['nullable', 'string', 'max:1000'],
            // LAUNCH review add-on — the recipe lines ticked "Can be
            // removed" (the product's own Remove list), each with an
            // optional customer label.
            ...RemovableIngredients::rules('removable'),

            // Physical items (P-G2) — same shape as the standalone PUT.
            'component_lines' => ['present', 'array', 'max:50'],
            'component_lines.*.component_uuid' => ['required', 'string', 'uuid'],
            'component_lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999.999'],

            // Branches (LAUNCH-P4 H6). NULL (or omitted) = skip the sync —
            // every branch, and the only legal value for branch-restricted
            // users (the controller 403s a non-null payload from them,
            // mirroring the standalone PUT). Otherwise { branch_scope:
            // 'all'|'selected', branch_ids }. No shelf counts (H7).
            'branches' => ['nullable', 'array'],
            'branches.branch_scope' => ['required_with:branches', 'string', Rule::in([Product::SCOPE_ALL, Product::SCOPE_SELECTED])],
            'branches.branch_ids' => ['nullable', 'array', 'max:500'],
            'branches.branch_ids.*' => ['integer', 'min:1'],

            // Delivery providers (LAUNCH-P4 B3): listed=false hides the
            // product on that provider; a blank price = the delivery price.
            'delivery_prices' => ['present', 'array', 'max:50'],
            'delivery_prices.*.provider_uuid' => ['required', 'string', 'uuid'],
            'delivery_prices.*.listed' => ['nullable', 'boolean'],
            'delivery_prices.*.price' => ['nullable', 'numeric', 'gt:0', 'max:999999.999'],
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
            $this->checkOwnedGroups($v);

            // LAUNCH-P4 H6 — "only selected branches" needs at least one.
            if ($this->input('branches.branch_scope') === Product::SCOPE_SELECTED && empty($this->input('branches.branch_ids'))) {
                $v->errors()->add('branches.branch_ids', 'Pick at least one branch, or choose all branches.');
            }
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
     * Owned-group names must be unique within the payload (LAUNCH-P4 M4:
     * pos_addon_groups_owner_name_unique is per owner product, and the
     * new product owns nothing yet, so a shared group or another
     * product's group with the same name does not clash). Checked here
     * so the user gets a per-group 422 instead of a mid-transaction DB
     * error. Min/max cross-checks mirror CreateAddOnGroupRequest.
     */
    private function checkOwnedGroups(Validator $v): void
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

            // LAUNCH-P4 M4 — owned group names are unique per owner
            // product (pos_addon_groups_owner_name_unique). The wizard
            // creates a brand-new product, which owns no groups yet, so
            // the only possible clash is within this form (above); a
            // shared or another product's group of the same name is fine.

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

    /**
     * LAUNCH review add-on — the product's dates ("Until" on or after
     * "From") and the "Can be removed" ticks (only lines of this recipe).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $v): void {
            MenuExtras::checkDates($v, $this->input('product.on_sale_from'), $this->input('product.on_sale_until'), 'product.on_sale_until');

            $recipe = array_map(
                static fn ($line): string => is_array($line) ? (string) ($line['ingredient_uuid'] ?? '') : '',
                is_array($this->input('recipe_lines')) ? $this->input('recipe_lines') : [],
            );
            RemovableIngredients::checkAgainstRecipe($v, 'removable', $this->input('removable'), $recipe);
        }];
    }
}
