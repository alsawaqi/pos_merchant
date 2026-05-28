<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Reports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7b-5 — validator for the Audit Log viewer (§5.12).
 *
 * Extends the standard ReportFilter shape with two
 * audit-log-specific filters:
 *
 *   - event     optional exact-match string (e.g. "discount.created")
 *   - actor_id  optional FK to portal users.id (scope events to one
 *               operator)
 *
 * Plus pagination knobs (page + per_page) because the audit log is
 * the only "report" surface that returns paginated rows -- the
 * §5.11 reports return aggregates.
 */
class AuditLogFilterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'branch_ids.*' => ['integer'],
            'event' => ['sometimes', 'nullable', 'string', 'max:128'],
            'actor_id' => ['sometimes', 'nullable', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
