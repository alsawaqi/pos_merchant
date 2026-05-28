<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Expenses;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/expenses/{expense:uuid}/reject.
 *
 * A rejection reason is mandatory (blueprint §5.10: "reject with
 * reason"). The Action also defends this, but the form request
 * gives the cleaner per-field 422.
 */
class RejectExpenseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_note' => ['required', 'string', 'max:1000'],
        ];
    }
}
