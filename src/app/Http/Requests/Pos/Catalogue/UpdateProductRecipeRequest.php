<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/products/{product:uuid}/recipe.
 *
 * Caller PUTs the full desired recipe — UpdateProductRecipeAction
 * performs an idempotent replace. Empty array is valid (means
 * "no recipe, no inventory deduction on sale").
 *
 * Cross-tenant + duplicate-ingredient validation happens in
 * the Action (it resolves all uuids in one query + dedupes
 * before any write). Validating here too would duplicate
 * effort with weaker error messages.
 *
 * Max 50 lines per recipe — generous upper bound. Typical
 * café products have 1-5 ingredients; complex dishes might
 * hit 10-20.
 */
class UpdateProductRecipeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.ingredient_uuid' => ['required', 'string', 'uuid'],
            // decimal(12,3) on the column; allow up to
            // 999,999.999 of any unit. Negative + zero
            // rejected because both make no recipe sense.
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            // #13 — per-line entered unit (alt-unit name, or null = base); the
            // quantity is converted to base before storage (kept base on device).
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            // Optional free-text the merchant can attach to
            // significant edits (e.g. "switched to organic
            // milk supplier"). Stored on the version row.
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
