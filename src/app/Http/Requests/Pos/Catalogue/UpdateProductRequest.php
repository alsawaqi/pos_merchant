<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'base_price' => ['sometimes', 'numeric', 'min:0', 'max:999999.999'],
            // Phase 4.9 — per-product delivery override.
            'delivery_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            // Phase 7 — stock mode: unit | ingredient | untracked.
            'stock_mode' => ['sometimes', 'string', 'in:unit,ingredient,untracked'],
            // Phase D2 — unit-mode LOW STOCK badge threshold. NULL = no badge.
            'low_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            // Phase D2 — §5.5.3 tax-inclusive flag (display-only for now).
            'tax_inclusive' => ['sometimes', 'boolean'],
            // Phase D2 — §5.5.3 "Show on Customer Tablet menu yes/no".
            'show_on_customer_tablet' => ['sometimes', 'boolean'],
            // G1 — menu time-window ('HH:MM:SS', NULL = no bound, both
            // NULL = always available, start > end wraps midnight).
            'available_from' => ['sometimes', 'nullable', 'string', 'regex:/^[0-2]\d:[0-5]\d(:[0-5]\d)?$/'],
            'available_until' => ['sometimes', 'nullable', 'string', 'regex:/^[0-2]\d:[0-5]\d(:[0-5]\d)?$/'],
            'display_order' => ['sometimes', 'integer', 'between:0,999'],
            'status' => ['sometimes', 'string', Rule::in(ProductStatus::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }
            /** @var Product|null $current */
            $current = $this->route('product');
            $currentId = $current?->id ?? 0;

            if ($this->has('category_id') && $this->filled('category_id')) {
                $categoryOwned = ProductCategory::query()
                    ->where('id', (int) $this->input('category_id'))
                    ->where('company_id', $companyId)
                    ->exists();
                if (! $categoryOwned) {
                    $v->errors()->add('category_id', 'The selected category does not belong to your company.');
                }
            }

            if ($this->has('sku')) {
                $sku = $this->input('sku');
                if (is_string($sku) && $sku !== '') {
                    $taken = Product::query()
                        ->where('company_id', $companyId)
                        ->where('sku', $sku)
                        ->where('id', '!=', $currentId)
                        ->exists();
                    if ($taken) {
                        $v->errors()->add('sku', 'A product with this SKU already exists at your company.');
                    }
                }
            }

            if ($this->has('barcode')) {
                $barcode = $this->input('barcode');
                if (is_string($barcode) && $barcode !== '') {
                    $taken = Product::query()
                        ->where('company_id', $companyId)
                        ->where('barcode', $barcode)
                        ->where('id', '!=', $currentId)
                        ->exists();
                    if ($taken) {
                        $v->errors()->add('barcode', 'A product with this barcode already exists at your company.');
                    }
                }
            }
        });
    }
}
