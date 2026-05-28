<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Loyalty;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/loyalty/config.
 *
 * All three fields are optional (partial update). The Action
 * enforces non-negative integers + the booleanness of is_active.
 *
 * Caps: 1000 points per OMR is far beyond any realistic
 * configuration (typical 1-10). 10000 baisas per point would
 * mean 1 point = 10 OMR — silly but technically valid.
 */
class UpsertLoyaltyConfigRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'points_per_omr' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'baisas_per_point' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
