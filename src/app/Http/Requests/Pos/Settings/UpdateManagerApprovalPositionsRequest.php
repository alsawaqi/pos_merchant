<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use App\Enums\StaffPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P-F1 — validate the manager-approval positions policy.
 *
 * At least one position is required (an empty list would leave every gated
 * POS action unauthorizable by PIN — a foot-gun); each must be a known
 * StaffPosition. Permission gating lives in the controller (orders.cancel).
 */
class UpdateManagerApprovalPositionsRequest extends FormRequest
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
