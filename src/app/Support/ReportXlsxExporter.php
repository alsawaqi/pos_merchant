<?php

declare(strict_types=1);

namespace App\Support;

use Shuchkin\SimpleXLSXGen;

/**
 * Phase D6 — render a report payload as an XLSX workbook.
 *
 * One worksheet per payload section (the same sections the CSV exporter
 * walks): table sections get a bold header row + data rows, summary
 * sections a two-column key/value sheet.
 *
 * Library note: SimpleXLSXGen packs the xlsx zip in pure PHP (the container
 * lacks ext-zip and ext-gd, which rules out openspout / phpspreadsheet /
 * maatwebsite). It auto-types cells and interprets whole-cell markup
 * (<b>…</b>, <style …>) — so:
 *
 *  - plain integer strings are passed as real ints (numeric cells);
 *  - decimal strings keep their exact scale via a <style nf="0.000">
 *    number format (money is decimal-3 — a bare '100.000' would
 *    otherwise display as 100);
 *  - every other string is passed through SimpleXLSXGen::raw() (a NUL
 *    prefix) which disables BOTH markup parsing and auto-typing —
 *    user-originated names can't inject styles/formulas/hyperlinks.
 */
final class ReportXlsxExporter
{
    private const int SHEET_NAME_MAX = 31;

    public function __construct(
        private readonly ReportSectionWalker $walker = new ReportSectionWalker(),
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toXlsx(array $payload): string
    {
        $xlsx = null;
        $usedNames = [];

        foreach ($this->walker->sections($payload) as $section) {
            $name = $this->sheetName($section->name, $usedNames);
            $rows = $this->sheetRows($section);

            if ($xlsx === null) {
                $xlsx = SimpleXLSXGen::fromArray($rows, $name);
            } else {
                $xlsx->addSheet($rows, $name);
            }
        }

        // Defensive: an XLSX needs at least one sheet.
        $xlsx ??= SimpleXLSXGen::fromArray([['']], 'Report');

        return (string) $xlsx;
    }

    /**
     * @return list<list<int|string>>
     */
    private function sheetRows(ReportSection $section): array
    {
        $rows = [];

        if ($section->kind === ReportSection::KIND_TABLE) {
            $rows[] = array_map(
                static fn (string $col): string => '<b>'.htmlspecialchars($col, ENT_QUOTES).'</b>',
                $section->columns,
            );
        }

        foreach ($section->rows as $row) {
            $rows[] = array_map(fn (string $cell): int|string => $this->cell($cell), $row);
        }

        // SimpleXLSXGen needs at least one row per sheet (empty summary blocks).
        return $rows === [] ? [['']] : $rows;
    }

    /**
     * Type a cell: ints and fixed-scale decimals become numeric cells,
     * everything else is raw text (no markup/auto-type interpretation).
     */
    private function cell(string $value): int|string
    {
        // Integers within PHP's safe range → real numeric cells.
        if (preg_match('/^-?\d{1,15}$/', $value) === 1) {
            return (int) $value;
        }

        // Fixed-scale decimals (money is decimal-3) → numeric cell with a
        // number format preserving the exact scale, e.g. nf="0.000".
        if (preg_match('/^-?\d{1,12}\.(\d{1,8})$/', $value, $m) === 1) {
            return '<style nf="0.'.str_repeat('0', strlen($m[1])).'">'.$value.'</style>';
        }

        return SimpleXLSXGen::raw($value);
    }

    /**
     * Excel sheet names: ≤31 chars, no []:*?/\ characters, unique per book.
     *
     * @param  array<string, true>  $used
     */
    private function sheetName(string $section, array &$used): string
    {
        $name = str_replace(['[', ']', ':', '*', '?', '/', '\\', "'"], '', $section);
        $name = trim($name) === '' ? 'sheet' : trim($name);
        $name = mb_substr($name, 0, self::SHEET_NAME_MAX);

        $candidate = $name;
        $i = 2;
        while (isset($used[mb_strtolower($candidate)])) {
            $suffix = ' '.$i++;
            $candidate = mb_substr($name, 0, self::SHEET_NAME_MAX - mb_strlen($suffix)).$suffix;
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
