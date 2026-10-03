<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

/**
 * LAUNCH-P4 B6 — the rows of an uploaded menu file, whatever its format:
 *   - .xlsx (recognised by its zip signature) through {@see XlsxReader};
 *   - CSV saved as "CSV UTF-8" by Excel: the byte-order mark is stripped,
 *     UTF-16 ("Unicode text") is converted, and the separator (comma,
 *     semicolon or tab) is taken from the header line. Any other encoding is
 *     refused with a clean message (Arabic needs UTF-8).
 *
 * Rows are keyed by their 1-based row (CSV: record) number; empty rows are
 * left out; cells are strings, gaps filled with ''.
 */
final class SpreadsheetRows
{
    public function __construct(
        private readonly XlsxReader $xlsx = new XlsxReader,
        private readonly int $maxRows = 5000,
    ) {}

    /**
     * @return array<int, list<string>>
     */
    public function read(string $bytes): array
    {
        if ($bytes === '') {
            throw new XlsxReaderException('empty', 'The file is empty.');
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return $this->xlsx->firstSheet($bytes);
        }

        return $this->csv($bytes);
    }

    /**
     * @return array<int, list<string>>
     */
    public function csv(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            $bytes = (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new XlsxReaderException('not_utf8', 'Save the file as "CSV UTF-8" (or as .xlsx) so Arabic text is kept.');
        }
        if (str_contains($bytes, "\0")) {
            throw new XlsxReaderException('not_xlsx', 'This is not an Excel .xlsx or CSV file.');
        }

        $firstLine = strtok($bytes, "\r\n");
        $firstLine = $firstLine === false ? '' : $firstLine;
        $delimiter = ',';
        $best = substr_count($firstLine, ',');
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($firstLine, $candidate) > $best) {
                $best = substr_count($firstLine, $candidate);
                $delimiter = $candidate;
            }
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new XlsxReaderException('corrupt', 'The file could not be read.');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        $rows = [];
        $number = 0;
        while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $number++;
            $cells = array_map(static fn ($v): string => (string) $v, $values);
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }
            if (count($rows) >= $this->maxRows) {
                fclose($stream);
                throw new XlsxReaderException('too_many_rows', 'The file has too many rows.');
            }
            $rows[$number] = array_values($cells);
        }
        fclose($stream);

        return $rows;
    }
}
