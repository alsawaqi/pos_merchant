<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phase D6 — shared report-payload normalizer.
 *
 * Every report payload is an associative array of sections; a section is
 * either a TABLE (non-empty list of assoc rows), a SUMMARY block (assoc of
 * scalars, e.g. `window`, `headline`) or a bare scalar. The CSV exporter
 * grew this classification first (Phase 7b); the XLSX + PDF exporters need
 * the exact same walk, so it lives here once and the three formats only
 * differ in rendering.
 *
 * Cell stringification matches the original CSV rules byte-for-byte:
 * booleans → "true"/"false", nested arrays → JSON, null → "".
 */
final class ReportSectionWalker
{
    /**
     * Normalize a report payload into renderable sections.
     *
     * @param  array<string, mixed>  $payload
     * @return list<ReportSection>
     */
    public function sections(array $payload): array
    {
        $sections = [];

        foreach ($payload as $name => $value) {
            $sections[] = $this->section((string) $name, $value);
        }

        return $sections;
    }

    private function section(string $name, mixed $value): ReportSection
    {
        if ($this->isTable($value)) {
            /** @var list<array<string, mixed>> $value */
            $columns = $this->columns($value);
            $rows = [];
            foreach ($value as $row) {
                $rows[] = array_map(fn (string $col): string => $this->scalar($row[$col] ?? null), $columns);
            }

            return new ReportSection($name, ReportSection::KIND_TABLE, $columns, $rows);
        }

        if (is_array($value)) {
            // Summary block: key/value pairs.
            $rows = [];
            foreach ($value as $key => $cell) {
                $rows[] = [(string) $key, $this->scalar($cell)];
            }

            return new ReportSection($name, ReportSection::KIND_SUMMARY, [], $rows);
        }

        // Bare scalar section.
        return new ReportSection($name, ReportSection::KIND_SCALAR, [], [[$this->scalar($value)]]);
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
