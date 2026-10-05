<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Models\Product;
use App\Support\Inventory\ItemCodes;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PD3a — validates PATCH /api/physical-items/{product:uuid}.
 *
 * LAUNCH review add-on (A4) — sku, unique across ingredients and products.
 */
class UpdatePhysicalItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],
            'purpose' => ['sometimes', 'string', 'in:packaging,general'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            $product = $this->route('product');
            $sku = ItemCodes::normalize($this->input('sku'));
            if ($companyId !== null && $sku !== '' && ($owner = ItemCodes::skuOwner($companyId, $sku, null, $product instanceof Product ? (int) $product->id : null)) !== null) {
                $v->errors()->add('sku', ItemCodes::skuMessage($owner));
            }
        });
    }
}
