<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\DeliveryProviders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/products/{uuid}/delivery-prices/{providerUuid}.
 *
 * LAUNCH-P4 B3 — { listed?: bool (default true), price?: decimal > 0 | null }.
 * listed=false hides the product on that provider; a NULL price means the
 * product's delivery price, else its base price. 0 is never a price.
 */
class SetProductDeliveryPriceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'price' => ['nullable', 'numeric', 'gt:0', 'max:999999.999'],
            'listed' => ['sometimes', 'boolean'],
        ];
    }
}
