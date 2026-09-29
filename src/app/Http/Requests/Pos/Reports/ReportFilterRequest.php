<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validator for every report endpoint. The blueprint
 * §5.11 reports all take the same filter shape:
 *
 *   - date_from / date_to    required ISO date strings;
 *                            evaluator runs [from-startOfDay,
 *                            to-endOfDay] inclusive
 *   - branch_ids             optional array of ints; NULL/empty
 *                            = all branches the actor has scope to
 *   - consolidated           optional bool (default true). When
 *                            false, the report returns per-branch
 *                            breakdowns where supported
 *
 * Per-report endpoints may extend with extra fields (e.g.
 * the Customer report's cohort_window parameter).
 */
class ReportFilterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function authorize(): bool
    {
        $scope = $this->user()?->allowedBranchIds();
        $requested = $this->input('branch_ids');
        if ($scope !== null && is_array($requested)) {
            abort_if(array_diff(array_map('intval', $requested), $scope) !== [], 403);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'branch_ids.*' => ['integer', Rule::exists('pos_branches', 'id')
                ->where(function ($query): void {
                    $query->where('company_id', $this->user()?->company_id)->whereNull('deleted_at');
                    $scope = $this->user()?->allowedBranchIds();
                    if ($scope !== null) {
                        $query->whereIn('id', $scope);
                    }
                })],
            'consolidated' => ['sometimes', 'boolean'],
        ];
    }
}
