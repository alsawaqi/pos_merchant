<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Taxes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/taxes.
 *
 * Name required (uniqueness checked in the Action). rate_percent is a
 * percentage 0–100 with up to 2 decimals — added on top of the bill (exclusive).
 */
class CreateTaxRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:64'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
