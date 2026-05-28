<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Expenses;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/expenses/{expense:uuid}/review.
 *
 * review_note is optional here (approval may carry an
 * annotation, or none). Rejection has its own request with a
 * REQUIRED reason.
 */
class ReviewExpenseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
