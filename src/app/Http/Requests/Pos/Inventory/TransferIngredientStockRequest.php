<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Actions\Pos\Inventory\TransferStockAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/ingredients/{ingredient:uuid}/stock/transfer — move
 * ingredient stock between two branches from the Stock dialog. Executes
 * through the existing {@see TransferStockAction}
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
            // Fix order B-2 — by container: rows and a lowerable total (amount_unit).
            'quantity' => ['required_without:containers', 'nullable', 'numeric', 'gt:0', 'max:999999.999'],
            'containers' => ['sometimes', 'array', 'max:20'],
            'containers.*.container_uuid' => ['required', 'string', 'max:64'],
            'containers.*.pieces' => ['required', 'numeric', 'gt:0', 'max:999999.9999'],
            'containers.*.leaf_pieces' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
            'amount_unit' => ['nullable', 'string', 'max:32'],
            // LAUNCH item kind, F4 — the unit the quantity is typed in (kg, l,
            // a pack size, '@piece'); null = the stored unit. Converted by
            // TransferStockAction like any transfer line.
            'unit' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
