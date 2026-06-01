<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Expenses;

use App\Enums\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/expenses (back-office log path).
 *
 * Branch tenancy is enforced in LogExpenseAction (it checks the
 * branch belongs to the acting company) — the form request only
 * validates shape, matching the discounts pattern.
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
            'category' => ['required', 'string', Rule::in(ExpenseCategory::values())],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'receipt_photo_path' => ['nullable', 'string', 'max:1024'],
            // When the expense actually occurred (defaults to now).
            'logged_at' => ['nullable', 'date'],
        ];
    }
}
