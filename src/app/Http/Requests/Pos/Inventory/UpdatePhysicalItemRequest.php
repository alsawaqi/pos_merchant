<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PD3a — validates PATCH /api/physical-items/{product:uuid}.
 */
class UpdatePhysicalItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],
            'purpose' => ['sometimes', 'string', 'in:packaging,general'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }
}
