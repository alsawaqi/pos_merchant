<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PD3a — validates POST /api/physical-items. A physical item is a thing
 * that cannot be eaten (cups, boxes, light bulbs); the controller forces
 * the storage row's product-ness (stock_mode unit, is_internal true,
 * base_price 0) — the merchant only describes the item.
 */
class CreatePhysicalItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            // 'packaging' = used with food (composition picker offers it);
            // 'general' = branch use (bulbs, cleaning), never on food.
            'purpose' => ['required', 'string', 'in:packaging,general'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
        ];
    }
}
