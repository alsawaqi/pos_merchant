<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Http\Requests\Pos\Inventory\Concerns\RequiresPurchaseCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/products/{product:uuid}/stock/receive-distribute — receive
 * a bulk quantity into the central pool AND split it across branches in one call.
 * `allocations` may be empty/omitted (everything then stays in the central pool).
 * The Action enforces that the distributed total does not exceed the received
 * quantity. PD5 — the cost rides the single receive (one purchase = one expense).
 */
class ReceiveAndDistributeProductStockRequest extends FormRequest
{
    use RequiresPurchaseCost;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.branch_uuid' => ['required', 'string', 'uuid'],
            'allocations.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ] + $this->purchaseCostRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->afterPurchaseCost($v));
    }
}
