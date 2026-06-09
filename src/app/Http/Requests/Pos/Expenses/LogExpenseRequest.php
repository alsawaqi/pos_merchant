<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Expenses;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/expenses (back-office log path).
 *
 * Branch tenancy + the per-company category check are enforced in
 * LogExpenseAction — the form request only validates shape (v2 #7:
 * category is now a free-form per-company key, no longer a fixed enum).
 */
class LogExpenseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable: a null/absent branch_id = a general /
            // company-wide expense (office, HQ, non-branch staff).
            'branch_id' => ['nullable', 'integer'],
            'category' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'receipt_photo_path' => ['nullable', 'string', 'max:1024'],
            // When the expense actually occurred (defaults to now).
            'logged_at' => ['nullable', 'date'],
        ];
    }
}
