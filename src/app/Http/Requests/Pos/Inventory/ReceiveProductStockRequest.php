<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Http\Requests\Pos\Inventory\Concerns\RequiresPurchaseCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/products/{product:uuid}/stock/receive — receive finished
 * goods into the product's central pool. PD2/PD5 — the cost is required (or an
 * explicit no_cost) so the buy books an expense ('stock_purchases', or
 * 'physical_items' for an internal item); delivery_cost books separately.
 */
class ReceiveProductStockRequest extends FormRequest
{
    use RequiresPurchaseCost;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ] + $this->purchaseCostRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->afterPurchaseCost($v));
    }
}
