<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/products.
 *
 * Cross-tenant guards (category ownership, SKU/barcode
 * uniqueness per company) are checked here so the user
 * gets clean 422s rather than the action's RuntimeException
 * surfacing as 500.
 */
class CreateProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            // OMR uses 3 decimals; min 0 allows free items
            // (loyalty redemptions, comps); max generous.
            'base_price' => ['required', 'numeric', 'min:0', 'max:999999.999'],
            // Phase 4.9 — per-product delivery override. NULL
            // means inherit base_price for delivery orders.
            'delivery_price' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            // Phase 7 — stock mode: unit (finished, piece-counted) | ingredient
            // (recipe-driven) | untracked (sold freely). Defaults to untracked.
            'stock_mode' => ['nullable', 'string', 'in:unit,ingredient,untracked'],
            // Phase D2 — unit-mode LOW STOCK badge threshold. NULL = no badge.
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            // Per-product tax override. 0 = zero-rated.
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Phase D2 — §5.5.3 tax-inclusive flag. STORED + DISPLAYED only
            // for now: totals still add company taxes on top (exclusive).
            'tax_inclusive' => ['nullable', 'boolean'],
            // Phase D2 — §5.5.3 "Show on Customer Tablet menu yes/no". The
            // future tablet menu consumes it; the staff POS ignores it.
            'show_on_customer_tablet' => ['nullable', 'boolean'],
            // G1 — menu time-window. 'HH:MM:SS' (seconds optional on the
            // wire), NULL = no bound. Both NULL = always available.
            // start > end wraps midnight (the pos_discounts convention).
            'available_from' => ['nullable', 'string', 'regex:/^[0-2]\d:[0-5]\d(:[0-5]\d)?$/'],
            'available_until' => ['nullable', 'string', 'regex:/^[0-2]\d:[0-5]\d(:[0-5]\d)?$/'],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            // Category ownership.
            if ($this->filled('category_id')) {
                $categoryOwned = ProductCategory::query()
                    ->where('id', (int) $this->input('category_id'))
                    ->where('company_id', $companyId)
                    ->exists();
                if (! $categoryOwned) {
                    $v->errors()->add('category_id', 'The selected category does not belong to your company.');
                }
            }

            // SKU uniqueness (when set).
            $sku = $this->input('sku');
            if (is_string($sku) && $sku !== '') {
                $taken = Product::query()
                    ->where('company_id', $companyId)
                    ->where('sku', $sku)
                    ->exists();
                if ($taken) {
                    $v->errors()->add('sku', 'A product with this SKU already exists at your company.');
                }
            }

            // Barcode uniqueness (when set).
            $barcode = $this->input('barcode');
            if (is_string($barcode) && $barcode !== '') {
                $taken = Product::query()
                    ->where('company_id', $companyId)
                    ->where('barcode', $barcode)
                    ->exists();
                if ($taken) {
                    $v->errors()->add('barcode', 'A product with this barcode already exists at your company.');
                }
            }
        });
    }
}
