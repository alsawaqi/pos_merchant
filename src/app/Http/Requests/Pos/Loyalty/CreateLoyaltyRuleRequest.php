<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Loyalty;

use App\Enums\LoyaltyRuleStatus;
use App\Enums\LoyaltyRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/loyalty/rules.
 *
 * config_json is validated as a free-form object — the per-type
 * key shape (min_order_value / stamps_required for visit_based,
 * points_per_omr / redemption_* for spend_based) is tolerated by
 * the evaluator (missing keys default to 0), so we don't over-
 * constrain it here.
 */
class CreateLoyaltyRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'string', Rule::in(LoyaltyRuleType::values())],
            'config_json' => ['sometimes', 'array'],
            'validity_start' => ['nullable', 'date'],
            'validity_end' => ['nullable', 'date', 'after:validity_start'],
            'status' => ['sometimes', 'string', Rule::in(LoyaltyRuleStatus::values())],
        ];
    }
}
