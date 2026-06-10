<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phase 7b — flatten a report payload to CSV.
 *
 * Reports return a multi-section associative array — some sections are a
 * summary block (assoc of scalars, e.g. `window`, `headline`) and some are a
 * table (a list of uniform rows, e.g. `by_branch`, `rows`, `by_rule`). This
 * renders each section in turn so one CSV faithfully carries the whole report:
 *
 *   # headline
 *   total_discount,10.000
 *   gross_sales,100.000
 *
 *   # by_branch
 *   branch_id,total_discount,gross_sales,...
 *   10,10.000,100.000,...
 *
 * Generic by design — every report (and any future one) exports with no
 * per-report code. Nested arrays in a cell are JSON-encoded; booleans render
 * as true/false.
 *
 * Phase D6 extracted the section classification into ReportSectionWalker
 * (shared with the XLSX + PDF exporters); the CSV output is byte-identical
 * to the pre-extraction renderer.
 */
final class ReportCsvExporter
{
    public function __construct(
        private readonly ReportSectionWalker $walker = new ReportSectionWalker(),
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toCsv(array $payload): string
    {
        $stream = fopen('php://temp', 'r+');
        $first = true;

        foreach ($this->walker->sections($payload) as $section) {
            if (! $first) {
                fwrite($stream, "\n");
            }
            $first = false;

            fputcsv($stream, ['# '.$section->name]);

            if ($section->kind === ReportSection::KIND_TABLE) {
                fputcsv($stream, $section->columns);
            }
            foreach ($section->rows as $row) {
                fputcsv($stream, $row);
            }
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
