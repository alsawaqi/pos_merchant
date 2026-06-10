<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Reports;

/**
 * Phase D6 — filter + format for GET reports/{report}/export.
 *
 * Extends the shared report filter with the download format. Lives on a
 * subclass (not ReportFilterRequest) so the 14 JSON report endpoints don't
 * silently accept an ignored `format` param.
 *
 *   - format omitted → csv (back-compat with the Phase 7b CSV-only route)
 *   - unknown format → 422 (explicit validation error, not a silent
 *     fallback — the SPA only sends the three known values)
 */
class ReportExportRequest extends ReportFilterRequest
{
    public const string DEFAULT_FORMAT = 'csv';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['sometimes', 'string', 'in:csv,xlsx,pdf'],
        ];
    }

    /**
     * The validated download format (csv when omitted). Named exportFormat()
     * because the base Illuminate\Http\Request already declares format().
     */
    public function exportFormat(): string
    {
        return $this->validated()['format'] ?? self::DEFAULT_FORMAT;
    }
}
