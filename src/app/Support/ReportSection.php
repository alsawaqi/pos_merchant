<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phase D6 — one normalized report section (see ReportSectionWalker).
 *
 *  - table:   $columns = header names, $rows = stringified cells per column
 *  - summary: $columns = [],           $rows = [key, value] pairs
 *  - scalar:  $columns = [],           $rows = [[value]]
 */
final readonly class ReportSection
{
    public const string KIND_TABLE = 'table';

    public const string KIND_SUMMARY = 'summary';

    public const string KIND_SCALAR = 'scalar';

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     */
    public function __construct(
        public string $name,
        public string $kind,
        public array $columns,
        public array $rows,
    ) {}
}
