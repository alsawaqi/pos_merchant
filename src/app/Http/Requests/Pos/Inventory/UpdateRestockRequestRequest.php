<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PATCH /api/restock-requests/{request:uuid}.
 *
 * Only legal while the request is in Draft (the Action
 * enforces this). 'lines' is required + min 1 to keep the
 * UX strict — saving an empty draft is not useful. Empty
 * array would cancel the request anyway (no lines + submit
 * fails), so removing the option avoids a confusing
 * intermediate state.
 *
 * Parent-level 'note' is optional and replaces whatever was
 * there.
 */
class UpdateRestockRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.ingredient_uuid' => ['required', 'string', 'uuid'],
            'lines.*.quantity_requested' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            // #13 — per-line entered unit (alt-unit name, or null = base).
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
