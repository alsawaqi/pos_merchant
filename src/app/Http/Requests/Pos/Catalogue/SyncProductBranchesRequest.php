<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/products/{product:uuid}/branches — the COMPLETE
 * desired set of per-branch assignments for a product. Each branch_id's
 * ownership is verified in the action (must belong to the actor's company).
 * An empty `branches` array clears all assignments = available everywhere.
 */
class SyncProductBranchesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branches' => ['present', 'array'],
            'branches.*.branch_id' => ['required', 'integer'],
            'branches.*.is_available' => ['required', 'boolean'],
            'branches.*.stock_qty' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
