<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAUNCH-P4 B4 — PUT /api/products/{product:uuid}/sold-out
 * { branch_id, sold_out: bool }.
 */
class SetProductSoldOutRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'sold_out' => ['required', 'boolean'],
        ];
    }
}
