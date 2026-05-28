<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/customers/{customer:uuid}/loyalty/adjust.
 *
 * Targets a specific rule's account (loyalty_rule_uuid). Points
 * and/or stamps may move; the Action enforces "at least one
 * non-zero" + the non-negative-balance guard. A reason is
 * mandatory (it's a manual money/points movement).
 */
class AdjustLoyaltyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'loyalty_rule_uuid' => ['required', 'string'],
            'points_delta' => ['sometimes', 'integer'],
            'stamps_delta' => ['sometimes', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
