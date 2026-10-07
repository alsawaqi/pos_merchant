<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\BranchScope;
use App\Support\Catalogue\ComboLinesInput;
use App\Support\Catalogue\MenuExtras;
use App\Support\Inventory\ItemCodes;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * LAUNCH-P4 B2 — validates POST /api/combos and PUT /api/combos/{uuid}.
 *
 * LAUNCH combo add-on (owner decisions 1-3, 2026-10-07) — a combo is a
 * product with its own price and a list of LINES ({@see ComboLinesInput}):
 * included items (a product × quantity, with optional upgrades at an upgrade
 * price) and choices ("pick N from a category", unticked items, extra prices).
 * The combo's own price follows the channel rules like any product (base /
 * delivery / per provider). It keeps no stock, recipe or components of its
 * own; its items keep theirs.
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

            // LAUNCH combo add-on — the combo's lines.
            ...ComboLinesInput::rules('lines'),

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
            ComboLinesInput::check($v, $companyId, $combo === null ? [] : ['combo_product_id' => (int) $combo->id],
                $this->input('lines'), $combo?->id !== null ? (int) $combo->id : null);

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
        // LAUNCH review add-on (A4, A5) — unique across ingredients and
        // products (SKU, case-insensitive) and the item barcodes (barcode).
        $sku = $this->input('sku');
        if (is_string($sku) && trim($sku) !== '' && ($owner = ItemCodes::skuOwner($companyId, $sku, null, $combo?->id !== null ? (int) $combo->id : null)) !== null) {
            $v->errors()->add('sku', ItemCodes::skuMessage($owner));
        }
        $barcode = $this->input('barcode');
        if (is_string($barcode) && trim($barcode) !== '' && ($owner = ItemCodes::barcodeOwner($companyId, $barcode, null, $combo?->id !== null ? (int) $combo->id : null)) !== null) {
            $v->errors()->add('barcode', ItemCodes::barcodeMessage($owner));
        }
    }

    /**
     * LAUNCH review add-on — the dates ("Until" on or after "From").
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $v): void {
            /** @var Product|null $combo */
            $combo = $this->route('product');

            // Fix order C-1, L1 — against the merged values: a date the
            // payload leaves out keeps its saved value (SaveComboAction), so
            // a lone "Until" before the saved "From" (or the reverse) is a
            // 422, never the database CHECK.
            $from = $this->has('on_sale_from') ? $this->input('on_sale_from') : $combo?->on_sale_from;
            $until = $this->has('on_sale_until') ? $this->input('on_sale_until') : $combo?->on_sale_until;
            MenuExtras::checkDates($v, $from, $until, $this->has('on_sale_until') ? 'on_sale_until' : 'on_sale_from');

        }];
    }
}
