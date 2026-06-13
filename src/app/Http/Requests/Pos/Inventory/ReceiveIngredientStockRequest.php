<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Http\Requests\Pos\Inventory\Concerns\RequiresPurchaseCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/ingredients/{ingredient:uuid}/stock/receive — receive a
 * purchase into the central warehouse pool. Quantity is in the ingredient's
 * BASE unit. PD5 — the cost is required (or an explicit no_cost) so the buy
 * books an 'ingredients' expense; delivery_cost books separately.
 */
class ReceiveIngredientStockRequest extends FormRequest
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
