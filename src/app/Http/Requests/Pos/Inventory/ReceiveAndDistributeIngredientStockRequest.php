<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Http\Requests\Pos\Inventory\Concerns\RequiresPurchaseCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/ingredients/{ingredient:uuid}/stock/receive-distribute —
 * receive a bulk quantity into the central warehouse AND split it across
 * branches in one call. `allocations` may be empty/omitted (everything then
 * stays in the warehouse). The Action enforces that the distributed total
 * does not exceed the received quantity. PD5 — the cost rides the single
 * receive (one purchase = one expense); allocations are free movement.
 */
class ReceiveAndDistributeIngredientStockRequest extends FormRequest
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
            // LAUNCH item kind, F4 — the unit the amounts are typed in (kg, l,
            // a pack size, '@piece'); null = the stored unit. Converted by the
            // controller (IngredientUnitConverter).
            'unit' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
        ] + $this->purchaseCostRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->afterPurchaseCost($v));
    }
}
