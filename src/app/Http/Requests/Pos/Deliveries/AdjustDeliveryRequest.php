<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Deliveries;

use App\Enums\MerchantPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/deliveries/{order:uuid}/adjust — confirm a single
 * pending delivery at the amount the provider ACTUALLY paid; the
 * difference vs the expected payout is stored as the reconciliation
 * variance. received_amount is an OMR decimal (0 allowed: a provider
 * writing an order off entirely is still a recorded settlement).
 */
class AdjustDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(MerchantPermission::DeliveriesManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'received_amount' => ['required', 'numeric', 'min:0', 'max:999999'],
        ];
    }
}
