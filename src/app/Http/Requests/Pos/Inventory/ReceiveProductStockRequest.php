<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/products/{product:uuid}/stock/receive — receive finished
 * goods into the product's central pool.
 */
class ReceiveProductStockRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            // PD2 — what was PAID for this delivery (bought-in goods are
            // purchases). > 0 books a 'stock_purchases' expense; null/0 =
            // no money recorded (e.g. a correction or a free sample).
            'total_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
