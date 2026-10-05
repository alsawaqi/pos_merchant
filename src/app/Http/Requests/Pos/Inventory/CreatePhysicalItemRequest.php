<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Support\Inventory\ItemCodes;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PD3a — validates POST /api/physical-items. A physical item is a thing
 * that cannot be eaten (cups, boxes, light bulbs); the controller forces
 * the storage row's product-ness (stock_mode unit, is_internal true,
 * base_price 0) — the merchant only describes the item.
 *
 * LAUNCH review add-on (A4) — sku: the supplier's code (unique across
 * ingredients and products, case-insensitive) or blank = generated PHY-0001.
 * The typed cost stays (physical items are not part of D1).
 */
class CreatePhysicalItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            // 'packaging' = used with food (composition picker offers it);
            // 'general' = branch use (bulbs, cleaning), never on food.
            'purpose' => ['required', 'string', 'in:packaging,general'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'sku' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            $sku = ItemCodes::normalize($this->input('sku'));
            if ($companyId !== null && $sku !== '' && ($owner = ItemCodes::skuOwner($companyId, $sku)) !== null) {
                $v->errors()->add('sku', ItemCodes::skuMessage($owner));
            }
        });
    }
}
