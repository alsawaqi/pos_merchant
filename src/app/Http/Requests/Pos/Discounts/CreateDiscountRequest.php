<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Discounts;

use App\Enums\DiscountAmountType;
use App\Enums\DiscountScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/discounts.
 *
 * Heavy validation surface — the Action enforces business
 * invariants (percent cap, validity window, target tenancy)
 * but the form-request layer catches the trivial shape errors
 * for cleaner per-field errors.
 */
class CreateDiscountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'scope' => ['required', 'string', Rule::in(DiscountScope::values())],
            'amount_type' => ['required', 'string', Rule::in(DiscountAmountType::values())],
            // Action enforces 0 < x <= 100 for percent.
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'validity_start' => ['nullable', 'date'],
            'validity_end' => ['nullable', 'date', 'after:validity_start'],
            'dayofweek_mask' => ['nullable', 'integer', 'min:0', 'max:127'],
            // HH:MM:SS pattern (24-hour). Action's midnight-wrap
            // logic doesn't need them ordered.
            'time_start' => ['nullable', 'string', 'regex:/^\\d{2}:\\d{2}:\\d{2}$/'],
            'time_end' => ['nullable', 'string', 'regex:/^\\d{2}:\\d{2}:\\d{2}$/'],
            'branch_scope_json' => ['nullable', 'array'],
            'branch_scope_json.*' => ['integer'],
            'stackable' => ['sometimes', 'boolean'],
            'requires_manager_approval' => ['sometimes', 'boolean'],
            // P-F4: order-scope auto-application. The Action forces TRUE
            // for product/category scopes (always automatic), whatever the
            // client sends.
            'auto_apply' => ['sometimes', 'boolean'],
        ];
    }
}
