<?php

declare(strict_types=1);

use App\Support\ReportSection;
use App\Support\ReportSectionWalker;
use App\Support\ReportXlsxExporter;

/**
 * Phase D6 — the shared payload normalizer behind the CSV/XLSX/PDF
 * exporters. Classification + stringification must match the original
 * CSV walker exactly (ragged rows, nested-array cells, bools, nulls).
 */
it('normalizes summary, table, ragged and scalar sections', function (): void {
    $sections = (new ReportSectionWalker)->sections([
        'window' => ['from' => '2026-06-01', 'consolidated' => true, 'branch_ids' => null],
        'by_branch' => [
            ['branch_id' => 1, 'gross' => '90.000'],
            ['branch_id' => 2, 'gross' => '10.000', 'extra' => [1, 2]], // ragged + nested array
        ],
        'note' => 'plain scalar',
    ]);

    expect($sections)->toHaveCount(3);

    [$window, $byBranch, $note] = $sections;

    expect($window->kind)->toBe(ReportSection::KIND_SUMMARY)
        ->and($window->rows)->toBe([
            ['from', '2026-06-01'],
            ['consolidated', 'true'],   // bool → true/false
            ['branch_ids', ''],         // null → ''
        ]);

    expect($byBranch->kind)->toBe(ReportSection::KIND_TABLE)
        ->and($byBranch->columns)->toBe(['branch_id', 'gross', 'extra']) // union of ragged keys
        ->and($byBranch->rows)->toBe([
            ['1', '90.000', ''],
            ['2', '10.000', '[1,2]'],   // nested array → JSON
        ]);

    expect($note->kind)->toBe(ReportSection::KIND_SCALAR)
        ->and($note->rows)->toBe([['plain scalar']]);
});

it('keeps an empty summary block as a section with no rows', function (): void {
    $sections = (new ReportSectionWalker)->sections(['empty_block' => []]);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->kind)->toBe(ReportSection::KIND_SUMMARY)
        ->and($sections[0]->rows)->toBe([]);
});

it('builds a valid xlsx from markup/formula-looking cells without choking', function (): void {
    // User-originated names go through SimpleXLSXGen::raw() (NUL prefix) so
    // whole-cell markup like <style …> / <b>…</b> is never parsed as styling
    // and auto-typing is skipped; this asserts the workbook still packs.
    $bin = (new ReportXlsxExporter)->toXlsx([
        'rows' => [
            ['name' => '<style bgcolor="#ff0000">x</style>', 'amount' => '10.000'],
            ['name' => '=SUM(A1:A9)', 'amount' => '5'],
        ],
    ]);

    expect(substr($bin, 0, 4))->toBe("PK\x03\x04");
});
