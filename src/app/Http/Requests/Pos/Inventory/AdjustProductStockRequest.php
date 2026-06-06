<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/products/{product:uuid}/stock/adjust — manual correction
 * to the central pool (branch_uuid omitted) or a branch count. Note required.
 */
class AdjustProductStockRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Omit / null = adjust the central pool; set = a branch count.
            'branch_uuid' => ['nullable', 'string', 'uuid'],
            'signed_quantity' => ['required', 'numeric', 'between:-999999.999,999999.999'],
            'note' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }
}
