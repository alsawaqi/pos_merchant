<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** LAUNCH-P4 B1 — PUT /api/settings/tax/prices-include-vat. */
class UpdatePricesIncludeVatRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prices_include_vat' => ['required', 'boolean'],
        ];
    }
}
