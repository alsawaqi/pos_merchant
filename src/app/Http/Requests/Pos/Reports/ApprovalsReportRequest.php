<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Reports;

use App\Actions\Pos\Reports\ApprovalsReportAction;
use Illuminate\Validation\Rule;

/**
 * LAUNCH-P5 B3 — the shared report filter plus the Approvals report's own
 * filters (action, approver, actor, result) and paging.
 */
class ApprovalsReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'action' => ['sometimes', 'nullable', 'string', 'max:64'],
            'approver_staff_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'actor_staff_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'result' => ['sometimes', 'nullable', 'string', Rule::in([...ApprovalsReportAction::RESULTS, 'problems'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
