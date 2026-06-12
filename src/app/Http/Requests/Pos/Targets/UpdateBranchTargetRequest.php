<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Targets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/branch-targets/{uuid}. Only the goal amount and
 * the active flag are mutable — structural changes (period / window
 * size / anchor) replace the target so finalized history stays keyed.
 */
class UpdateBranchTargetRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
