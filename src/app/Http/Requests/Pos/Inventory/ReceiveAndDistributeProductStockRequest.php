<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/products/{product:uuid}/stock/receive-distribute — receive
 * a bulk quantity into the central pool AND split it across branches in one call.
 * `allocations` may be empty/omitted (everything then stays in the central pool).
 * The Action enforces that the distributed total does not exceed the received
 * quantity.
 */
class ReceiveAndDistributeProductStockRequest extends FormRequest
{
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
        ];
    }
}
