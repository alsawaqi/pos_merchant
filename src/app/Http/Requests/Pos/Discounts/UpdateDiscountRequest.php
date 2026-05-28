<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Discounts;

use App\Enums\DiscountAmountType;
use App\Enums\DiscountScope;
use App\Enums\DiscountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/discounts/{discount:uuid}.
 *
 * All fields optional (partial update). Status edits are
 * allowed here but the Pause + Resume Actions are cleaner
 * for the single-status-flip case.
 */
class UpdateDiscountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'scope' => ['sometimes', 'string', Rule::in(DiscountScope::values())],
            'amount_type' => ['sometimes', 'string', Rule::in(DiscountAmountType::values())],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:999999.999'],
            'validity_start' => ['sometimes', 'nullable', 'date'],
            'validity_end' => ['sometimes', 'nullable', 'date'],
            'dayofweek_mask' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:127'],
            'time_start' => ['sometimes', 'nullable', 'string', 'regex:/^\\d{2}:\\d{2}:\\d{2}$/'],
            'time_end' => ['sometimes', 'nullable', 'string', 'regex:/^\\d{2}:\\d{2}:\\d{2}$/'],
            'branch_scope_json' => ['sometimes', 'nullable', 'array'],
            'branch_scope_json.*' => ['integer'],
            'stackable' => ['sometimes', 'boolean'],
            'requires_manager_approval' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(DiscountStatus::values())],
        ];
    }
}
