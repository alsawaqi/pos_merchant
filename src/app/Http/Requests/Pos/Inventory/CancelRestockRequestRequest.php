<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/restock-requests/{request:uuid}/cancel.
 *
 * Cancellation reason is optional — the merchant may just want
 * to drop a draft they no longer need. When provided it's
 * stored on review_note (reusing the column rather than adding
 * a dedicated cancellation_note).
 */
class CancelRestockRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
