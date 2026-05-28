<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\DeliveryProviders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/products/{uuid}/delivery-prices/{providerUuid}.
 *
 * price must be > 0 (zero is rejected by the Action; here we
 * also catch it at the form layer for a cleaner error).
 */
class SetProductDeliveryPriceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'price' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
        ];
    }
}
