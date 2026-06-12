<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/ingredients/{ingredient:uuid}/stock/transfer — move
 * ingredient stock between two branches from the Stock dialog. Executes
 * through the existing {@see \App\Actions\Pos\Inventory\TransferStockAction}
 * (single line), so it lands as a regular BranchTransfer with its paired
 * transfer_out / transfer_in movements.
 */
class TransferIngredientStockRequest extends FormRequest
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
