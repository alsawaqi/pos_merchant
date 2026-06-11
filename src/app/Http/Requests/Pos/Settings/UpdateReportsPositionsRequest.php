<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use App\Enums\StaffPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P-F6 — validate the device-reports access policy.
 *
 * At least one position is required (an empty list would leave the device
 * Reports dashboard unreachable for everyone — managers included); each
 * must be a known StaffPosition. Permission gating lives in the controller
 * (orders.cancel, the sibling policies' gate).
 */
class UpdateReportsPositionsRequest extends FormRequest
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
