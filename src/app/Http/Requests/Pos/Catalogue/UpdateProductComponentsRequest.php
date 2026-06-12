<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P-G2 — validate PUT /api/products/{uuid}/components.
 *
 * Full-replace payload (the recipe endpoint convention): empty lines =
 * the product consumes no physical items. Ownership / unit-mode /
 * self-reference guards live in UpdateProductComponentsAction.
 */
class UpdateProductComponentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.component_uuid' => ['required', 'string', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999.999'],
        ];
    }
}
