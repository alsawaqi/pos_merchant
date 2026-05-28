<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Loyalty;

use App\Enums\LoyaltyRuleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/loyalty/rules/{rule:uuid}.
 *
 * Type is immutable (a visit↔spend switch would break existing
 * account balances) so it is NOT accepted here.
 */
class UpdateLoyaltyRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'config_json' => ['sometimes', 'array'],
            'validity_start' => ['sometimes', 'nullable', 'date'],
            'validity_end' => ['sometimes', 'nullable', 'date', 'after:validity_start'],
            'status' => ['sometimes', 'string', Rule::in(LoyaltyRuleStatus::values())],
        ];
    }
}
