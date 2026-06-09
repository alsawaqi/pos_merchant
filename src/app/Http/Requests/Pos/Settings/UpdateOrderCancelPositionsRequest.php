<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use App\Enums\StaffPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v2 #14 — validate the order-cancel positions policy.
 *
 * At least one position is required (an empty list would silently disable
 * cancellation everywhere — a foot-gun); each must be a known StaffPosition.
 * Permission gating lives in the controller (orders.cancel).
 */
class UpdateOrderCancelPositionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'positions' => ['required', 'array', 'min:1'],
            'positions.*' => ['string', Rule::in(StaffPosition::values())],
        ];
    }
}
