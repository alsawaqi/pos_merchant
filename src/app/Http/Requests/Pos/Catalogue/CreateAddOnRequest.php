<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/addon-groups/{group:uuid}/addons.
 *
 * The group's tenant ownership is verified by the controller
 * via refuseIfNotInTenant on the route-bound model — by the
 * time validation runs we know it's ours.
 */
class CreateAddOnRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['nullable', 'string', 'max:100'],
            // 0..999.999 OMR. Negative deltas not allowed (an
            // add-on that lowers the price is a discount, not
            // an add-on — separate concept in §5.9).
            'price_delta' => ['nullable', 'numeric', 'min:0', 'max:999.999'],
            'is_default' => ['nullable', 'boolean'],
            // P-G3 — the add-on IS this product (cake inside a coffee):
            // selling it consumes the product's real stock by its type.
            // null = a classic label-only option.
            'linked_product_uuid' => ['nullable', 'string', 'uuid'],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
        ];
    }
}
