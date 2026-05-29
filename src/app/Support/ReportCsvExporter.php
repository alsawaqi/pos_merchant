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
 */
final class ReportCsvExporter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function toCsv(array $payload): string
    {
        $stream = fopen('php://temp', 'r+');
        $first = true;

        foreach ($payload as $section => $value) {
            if (! $first) {
                fwrite($stream, "\n");
            }
            $first = false;
            $this->writeSection($stream, (string) $section, $value);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @param  resource  $stream
     */
    private function writeSection($stream, string $name, mixed $value): void
    {
        fputcsv($stream, ['# '.$name]);

        if ($this->isTable($value)) {
            /** @var list<array<string, mixed>> $value */
            $columns = $this->columns($value);
            fputcsv($stream, $columns);
            foreach ($value as $row) {
                fputcsv($stream, array_map(fn ($col): string => $this->scalar($row[$col] ?? null), $columns));
            }

            return;
        }

        if (is_array($value)) {
            // Summary block: key,value rows.
            foreach ($value as $key => $cell) {
                fputcsv($stream, [(string) $key, $this->scalar($cell)]);
            }

            return;
        }

        // Bare scalar section.
        fputcsv($stream, [$this->scalar($value)]);
    }

    /**
     * A non-empty list whose first element is an array → a table of rows.
     */
    private function isTable(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value !== [] && is_array($value[0]);
    }

    /**
     * Union of keys across all rows (rows may be ragged).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function columns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[(string) $key] = true;
            }
        }

        return array_keys($columns);
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }

        return (string) ($value ?? '');
    }
}
