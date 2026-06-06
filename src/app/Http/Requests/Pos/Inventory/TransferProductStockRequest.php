<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/products/{product:uuid}/stock/transfer — move units from
 * one branch to another.
 */
class TransferProductStockRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_branch_uuid' => ['required', 'string', 'uuid'],
            'to_branch_uuid' => ['required', 'string', 'uuid', 'different:from_branch_uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
