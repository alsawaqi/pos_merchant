<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use DOMElement;
use DOMNode;
use XMLReader;

/**
 * LAUNCH-P4 B6 — reads the first worksheet of an .xlsx file with nothing but
 * zlib and the XML extensions (the PHP image has no ext-zip, and no new
 * composer package is allowed).
 *
 * An .xlsx is a zip archive of XML parts. This class:
 *   1. finds the zip's end-of-central-directory record and walks the central
 *      directory (names, methods, sizes, CRCs, local-header offsets);
 *   2. reads only the parts it needs — xl/workbook.xml and its rels (to find
 *      the first sheet), xl/sharedStrings.xml and that sheet — from their
 *      local headers: method 0 (stored) as is, method 8 (deflate) through an
 *      incremental raw inflate;
 *   3. parses the XML with XMLReader (no network, no entity expansion).
 *
 * Guards (a zip bomb or a broken file ends in a clean XlsxReaderException,
 * never a memory blow-up or a PHP warning): per-part and total uncompressed
 * caps checked while inflating, the declared size and the CRC must match, at
 * most N entries / rows / columns, no encryption, no zip64, no other methods.
 *
 * Cells come back as strings: shared strings ("s"), inline strings
 * ("inlineStr"), plain values (numbers as Excel stored them), booleans as
 * TRUE / FALSE; formulas give their cached value. Gaps are filled with '' and
 * the result is keyed by the sheet's own 1-based row numbers; empty rows are
 * left out.
 */
final class XlsxReader
{
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const SIG_EOCD = 0x06054B50;

    private const SIG_CENTRAL = 0x02014B50;

    private const SIG_LOCAL = 0x04034B50;

    private const INFLATE_CHUNK = 4096;

    private int $inflatedTotal = 0;

    public function __construct(
        private readonly int $maxEntryBytes = 16 * 1024 * 1024,
        private readonly int $maxTotalBytes = 32 * 1024 * 1024,
        private readonly int $maxRows = 5000,
        private readonly int $maxColumns = 64,
        private readonly int $maxEntries = 4000,
    ) {}

    /**
     * @return array<int, list<string>> sheet row number => cells (A = index 0)
     */
    public function firstSheet(string $bytes): array
    {
        $this->inflatedTotal = 0;
        $entries = $this->centralDirectory($bytes);

        $shared = isset($entries['xl/sharedStrings.xml'])
            ? $this->sharedStrings($this->extract($bytes, $entries['xl/sharedStrings.xml']))
            : [];

        $sheetPath = $this->firstSheetPath($bytes, $entries);
        if ($sheetPath === null) {
            throw new XlsxReaderException('no_sheet', 'The workbook has no worksheet.');
        }

        return $this->rows($this->extract($bytes, $entries[$sheetPath]), $shared);
    }

    // ---- zip ---------------------------------------------------------------

    /**
     * @return array<string, array{method: int, flags: int, crc: int, compressed: int, size: int, offset: int}>
     */
    private function centralDirectory(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < 22 || ! str_starts_with($bytes, "PK\x03\x04")) {
            throw new XlsxReaderException('not_xlsx', 'This is not an Excel .xlsx file.');
        }

        $searchFrom = max(0, $length - 22 - 0xFFFF);
        $eocd = strrpos(substr($bytes, $searchFrom), "PK\x05\x06");
        if ($eocd === false) {
            throw new XlsxReaderException('corrupt', 'The file is damaged (no zip directory).');
        }
        $eocd += $searchFrom;
        if ($eocd + 22 > $length) {
            throw new XlsxReaderException('corrupt', 'The file is damaged (cut short).');
        }
        /** @var array{sig: int, disk: int, cdDisk: int, diskEntries: int, entries: int, cdSize: int, cdOffset: int, commentLength: int} $end */
        $end = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset/vcommentLength', substr($bytes, $eocd, 22));
        if ($end['entries'] === 0xFFFF || $end['cdOffset'] === 0xFFFFFFFF || $end['cdSize'] === 0xFFFFFFFF) {
            throw new XlsxReaderException('unsupported', 'This workbook format (zip64) is not supported. Save it again as a normal .xlsx file.');
        }
        if ($end['disk'] !== 0 || $end['cdDisk'] !== 0) {
            throw new XlsxReaderException('unsupported', 'Split archives are not supported.');
        }
        if ($end['entries'] > $this->maxEntries) {
            throw new XlsxReaderException('too_large', 'The workbook has too many parts.');
        }
        if ($eocd < $end['cdOffset'] + $end['cdSize']) {
            throw new XlsxReaderException('corrupt', 'The file is damaged (bad zip directory).');
        }

        $entries = [];
        $p = $end['cdOffset'];
        for ($i = 0; $i < $end['entries']; $i++) {
            if ($p + 46 > $eocd) {
                throw new XlsxReaderException('corrupt', 'The file is damaged (bad zip directory).');
            }
            /** @var array<string, int> $h */
            $h = unpack('Vsig/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternal/Vexternal/Voffset', substr($bytes, $p, 46));
            if ($h['sig'] !== self::SIG_CENTRAL) {
                throw new XlsxReaderException('corrupt', 'The file is damaged (bad zip directory).');
            }
            $name = substr($bytes, $p + 46, $h['nameLength']);
            $entries[$name] = [
                'method' => $h['method'],
                'flags' => $h['flags'],
                'crc' => $h['crc'],
                'compressed' => $h['compressed'],
                'size' => $h['size'],
                'offset' => $h['offset'],
            ];
            $p += 46 + $h['nameLength'] + $h['extraLength'] + $h['commentLength'];
        }

        return $entries;
    }

    /**
     * @param  array{method: int, flags: int, crc: int, compressed: int, size: int, offset: int}  $entry
     */
    private function extract(string $bytes, array $entry): string
    {
        if (($entry['flags'] & 0x1) === 0x1) {
            throw new XlsxReaderException('encrypted', 'The workbook is password-protected. Remove the password and try again.');
        }
        $limit = min($this->maxEntryBytes, $this->maxTotalBytes - $this->inflatedTotal);
        if ($entry['size'] > $limit) {
            throw new XlsxReaderException('too_large', 'The workbook is too large.');
        }
        if (strlen($bytes) < $entry['offset'] + 30) {
            throw new XlsxReaderException('corrupt', 'The file is damaged (bad entry).');
        }
        /** @var array<string, int> $local */
        $local = unpack('Vsig/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', substr($bytes, $entry['offset'], 30));
        if ($local['sig'] !== self::SIG_LOCAL) {
            throw new XlsxReaderException('corrupt', 'The file is damaged (bad entry).');
        }
        $start = $entry['offset'] + 30 + $local['nameLength'] + $local['extraLength'];
        $compressed = substr($bytes, $start, $entry['compressed']);
        if (strlen($compressed) !== $entry['compressed']) {
            throw new XlsxReaderException('corrupt', 'The file is damaged (cut short).');
        }

        $data = match ($entry['method']) {
            0 => $compressed,
            8 => $this->inflate($compressed, $limit),
            default => throw new XlsxReaderException('unsupported', 'This workbook uses a compression that is not supported. Save it again as a normal .xlsx file.'),
        };

        if (strlen($data) > $limit) {
            throw new XlsxReaderException('too_large', 'The workbook is too large.');
        }
        if (strlen($data) !== $entry['size'] || crc32($data) !== $entry['crc']) {
            throw new XlsxReaderException('corrupt', 'The file is damaged (a part does not match its checksum).');
        }
        $this->inflatedTotal += strlen($data);

        return $data;
    }

    /** Raw deflate, in small steps, stopping as soon as the output passes $limit. */
    private function inflate(string $compressed, int $limit): string
    {
        $context = inflate_init(ZLIB_ENCODING_RAW);
        if ($context === false) {
            throw new XlsxReaderException('corrupt', 'The file is damaged.');
        }
        $out = '';
        $length = strlen($compressed);
        for ($offset = 0; $offset < $length; $offset += self::INFLATE_CHUNK) {
            $last = $offset + self::INFLATE_CHUNK >= $length;
            $chunk = @inflate_add($context, substr($compressed, $offset, self::INFLATE_CHUNK), $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($chunk === false) {
                throw new XlsxReaderException('corrupt', 'The file is damaged (bad compressed data).');
            }
            $out .= $chunk;
            if (strlen($out) > $limit) {
                throw new XlsxReaderException('too_large', 'The workbook is too large.');
            }
        }

        return $out;
    }

    // ---- workbook ------------------------------------------------------------

    /**
     * The first sheet listed in the workbook (through its relationship), else
     * the first xl/worksheets/*.xml part.
     *
     * @param  array<string, array{method: int, flags: int, crc: int, compressed: int, size: int, offset: int}>  $entries
     */
    private function firstSheetPath(string $bytes, array $entries): ?string
    {
        if (isset($entries['xl/workbook.xml'], $entries['xl/_rels/workbook.xml.rels'])) {
            $relId = null;
            $reader = $this->xml($this->extract($bytes, $entries['xl/workbook.xml']));
            while ($this->next($reader)) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                    $relId = $reader->getAttributeNs('id', self::REL_NS);
                    break;
                }
            }
            $reader->close();

            if ($relId !== null && $relId !== '') {
                $rels = $this->xml($this->extract($bytes, $entries['xl/_rels/workbook.xml.rels']));
                $target = null;
                while ($this->next($rels)) {
                    if ($rels->nodeType === XMLReader::ELEMENT && $rels->localName === 'Relationship' && $rels->getAttribute('Id') === $relId) {
                        $target = (string) $rels->getAttribute('Target');
                        break;
                    }
                }
                $rels->close();
                if ($target !== null && $target !== '') {
                    $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                    $path = (string) preg_replace('#[^/]+/\.\./#', '', $path);
                    if (isset($entries[$path])) {
                        return $path;
                    }
                }
            }
        }

        $sheets = array_values(array_filter(array_keys($entries), static fn (string $name): bool => (bool) preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)));
        natsort($sheets);

        return $sheets === [] ? null : (string) reset($sheets);
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(string $xml): array
    {
        $strings = [];
        $reader = $this->xml($xml);
        while ($this->next($reader)) {
            // read() then walks the item's own children, none of them <si>.
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $node = $reader->expand();
                $strings[] = $node === false ? '' : self::text($node);
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * @param  list<string>  $shared
     * @return array<int, list<string>>
     */
    private function rows(string $xml, array $shared): array
    {
        $rows = [];
        $lastRow = 0;
        $reader = $this->xml($xml);
        while ($this->next($reader)) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $number = (int) $reader->getAttribute('r');
            $number = $number > 0 ? $number : $lastRow + 1;
            $lastRow = $number;
            $node = $reader->expand();
            if ($node === false) {
                throw new XlsxReaderException('corrupt', 'The worksheet is damaged.');
            }

            $cells = [];
            $column = -1;
            foreach ($node->childNodes as $cell) {
                if (! $cell instanceof DOMElement || $cell->localName !== 'c') {
                    continue;
                }
                $ref = $cell->getAttribute('r');
                $column = $ref !== '' ? self::columnIndex($ref) : $column + 1;
                if ($column < 0 || $column >= $this->maxColumns) {
                    continue;
                }
                $cells[$column] = $this->cellValue($cell, $shared);
            }

            if ($cells === [] || implode('', $cells) === '') {
                continue;
            }
            if (count($rows) >= $this->maxRows) {
                throw new XlsxReaderException('too_many_rows', 'The sheet has too many rows.');
            }
            $filled = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $index => $value) {
                $filled[$index] = $value;
            }
            $rows[$number] = $filled;
        }
        $reader->close();

        return $rows;
    }

    /**
     * @param  list<string>  $shared
     */
    private function cellValue(DOMElement $cell, array $shared): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            foreach ($cell->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'is') {
                    return self::text($child);
                }
            }

            return '';
        }
        $value = null;
        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'v') {
                $value = $child->textContent;
                break;
            }
        }
        if ($value === null) {
            return '';
        }

        return match ($type) {
            's' => $shared[(int) $value] ?? '',
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            'e' => '',
            default => $value,
        };
    }

    /** The text of a string item: every <t>, except phonetic runs (<rPh>). */
    private static function text(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 't') {
                $out .= $child->textContent;
            } elseif ($child->localName !== 'rPh') {
                $out .= self::text($child);
            }
        }

        return $out;
    }

    /** "C7" → 2, "AA10" → 26. */
    private static function columnIndex(string $reference): int
    {
        if (preg_match('/^([A-Za-z]{1,3})\d*$/', $reference, $m) !== 1) {
            return -1;
        }
        $index = 0;
        foreach (str_split(strtoupper($m[1])) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function xml(string $xml): XMLReader
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        if (! $reader instanceof XMLReader) {
            throw new XlsxReaderException('corrupt', 'The workbook is damaged (bad XML).');
        }

        return $reader;
    }

    /** XMLReader::read() that turns a parse error into a clean exception. */
    private function next(XMLReader $reader): bool
    {
        $ok = @$reader->read();
        $error = libxml_get_last_error();
        if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
            libxml_clear_errors();
            throw new XlsxReaderException('corrupt', 'The workbook is damaged (bad XML).');
        }

        return $ok;
    }
}
