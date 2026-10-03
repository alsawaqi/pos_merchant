<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B6 — the built-in .xlsx reader (no ext-zip in the PHP image, no
 * new package): zip central directory + raw inflate (stored entries too) +
 * XMLReader. Checked against a workbook made by SimpleXLSXGen and against
 * hand-built archives: shared and inline strings, Arabic, empty cells and
 * gaps, a corrupt or non-zip file (a clean error), and the zip-bomb guard
 * (the uncompressed size is capped while inflating). Before: there was no
 * reader (the class did not exist).
 */

use App\Support\Spreadsheet\SpreadsheetRows;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxReaderException;
use Shuchkin\SimpleXLSXGen;

/**
 * A minimal zip writer for the tests: $files = [name => content]; method 8 =
 * deflate, 0 = stored. $mutate may tamper with an entry's header fields.
 *
 * @param  array<string, string>  $files
 * @param  array<string, array<string, int>>  $mutate  name => [crc|size|flags => value]
 */
function p4Zip(array $files, int $method = 8, array $mutate = []): string
{
    $out = '';
    $central = '';
    foreach ($files as $name => $content) {
        $data = $method === 8 ? gzdeflate($content, 9) : $content;
        $crc = $mutate[$name]['crc'] ?? crc32($content);
        $size = $mutate[$name]['size'] ?? strlen($content);
        $flags = $mutate[$name]['flags'] ?? 0;
        $offset = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $method, 0, 0, $crc, strlen($data), $size, strlen($name), 0).$name.$data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $flags, $method, 0, 0, $crc, strlen($data), $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
    }

    return $out.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
}

/** The parts of a workbook whose first sheet is $sheetXml. */
function p4Workbook(string $sheetXml, ?string $sharedXml = null, string $sheetFile = 'sheet1.xml'): array
{
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Menu" sheetId="1" r:id="rId7"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/'.$sheetFile.'"/></Relationships>',
        'xl/worksheets/'.$sheetFile => $sheetXml,
    ];
    if ($sharedXml !== null) {
        $files['xl/sharedStrings.xml'] = $sharedXml;
    }

    return $files;
}

it('reads back a workbook made by SimpleXLSXGen, Arabic and numbers included', function (): void {
    $bytes = (string) SimpleXLSXGen::fromArray([
        ['name', 'name_ar', 'price'],
        ['Latte', 'لاتيه', 1.5],
        ['Karak', 'شاي كرك', '0.300'],
    ], 'Menu');

    $rows = (new XlsxReader)->firstSheet($bytes);

    expect($rows)->toBe([
        1 => ['name', 'name_ar', 'price'],
        2 => ['Latte', 'لاتيه', '1.5'],
        // The number is kept exactly as the workbook stores it.
        3 => ['Karak', 'شاي كرك', '0.300'],
    ]);
});

it('reads shared strings, inline strings, booleans and rich text, ignoring phonetic runs', function (): void {
    $shared = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3" uniqueCount="3">'
        .'<si><t>name</t></si>'
        .'<si><r><t>شاورما </t></r><r><rPr><b/></rPr><t>دجاج</t></r><rPh><t>ignored</t></rPh></si>'
        .'<si><t xml:space="preserve"> Hot drinks </t></si></sst>';
    $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>active</t></is></c></row>'
        .'<row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2" t="b"><v>1</v></c><c r="C2" t="s"><v>2</v></c><c r="D2"><f>1+1</f><v>2</v></c></row>'
        .'</sheetData></worksheet>';

    $rows = (new XlsxReader)->firstSheet(p4Zip(p4Workbook($sheet, $shared)));

    expect($rows)->toBe([
        1 => ['name', 'active'],
        2 => ['شاورما دجاج', 'TRUE', ' Hot drinks ', '2'],
    ]);
});

it('fills gaps between cells and keeps the sheet row numbers, skipping empty rows', function (): void {
    $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c><c r="D1" t="inlineStr"><is><t>d</t></is></c></row>'
        .'<row r="2"><c r="B2" t="inlineStr"><is><t></t></is></c></row>'
        .'<row r="5"><c r="C5"><v>7</v></c></row>'
        .'<row><c t="inlineStr"><is><t>no refs</t></is></c><c><v>8</v></c></row>'
        .'</sheetData></worksheet>';

    $rows = (new XlsxReader)->firstSheet(p4Zip(p4Workbook($sheet), 0));

    expect($rows)->toBe([
        1 => ['a', '', '', 'd'],
        5 => ['', '', '7'],
        6 => ['no refs', '8'],
    ]);
});

it('follows the workbook to its first sheet, whatever its file name, and works with a prefixed namespace', function (): void {
    $sheet = '<?xml version="1.0"?><x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:sheetData>'
        .'<x:row r="1"><x:c r="A1" t="inlineStr"><x:is><x:t>first</x:t></x:is></x:c></x:row></x:sheetData></x:worksheet>';
    $files = p4Workbook($sheet, null, 'menu.xml');
    $files['xl/worksheets/sheet1.xml'] = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>other</t></is></c></row></sheetData></worksheet>';

    expect((new XlsxReader)->firstSheet(p4Zip($files)))->toBe([1 => ['first']]);
});

it('refuses a file that is not a zip, a damaged zip and a password-protected one, cleanly', function (): void {
    $reader = new XlsxReader;
    $reason = static function (callable $read): string {
        try {
            $read();
        } catch (XlsxReaderException $e) {
            return $e->reason;
        }

        return 'no error';
    };
    $good = p4Zip(p4Workbook('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>'));

    expect($reason(fn () => $reader->firstSheet("name,price\nTea,0.300\n")))->toBe('not_xlsx')
        ->and($reason(fn () => $reader->firstSheet(substr($good, 0, (int) (strlen($good) / 2)))))->toBe('corrupt')
        ->and($reason(fn () => $reader->firstSheet(p4Zip(['xl/worksheets/sheet1.xml' => '<worksheet/>'], 8, ['xl/worksheets/sheet1.xml' => ['crc' => 12345]]))))->toBe('corrupt')
        ->and($reason(fn () => $reader->firstSheet(p4Zip(p4Workbook('<worksheet><sheetData><row'.str_repeat('<', 3))))))->toBe('corrupt')
        ->and($reason(fn () => $reader->firstSheet(p4Zip(['xl/worksheets/sheet1.xml' => '<worksheet/>'], 8, ['xl/worksheets/sheet1.xml' => ['flags' => 1]]))))->toBe('encrypted')
        ->and($reason(fn () => $reader->firstSheet(p4Zip(['docProps/app.xml' => '<x/>']))))->toBe('no_sheet');
});

it('stops a zip bomb while inflating, even when the header lies about the size', function (): void {
    $zeros = str_repeat("\0", 3 * 1024 * 1024);
    $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>'.$zeros;
    $reader = new XlsxReader(maxEntryBytes: 1024 * 1024, maxTotalBytes: 2 * 1024 * 1024);

    // Honest header: refused before inflating.
    expect(fn () => $reader->firstSheet(p4Zip(p4Workbook($sheet))))->toThrow(XlsxReaderException::class, 'too large');
    // Lying header (declares 100 bytes): refused while inflating, at the cap.
    $lying = p4Zip(p4Workbook($sheet), 8, ['xl/worksheets/sheet1.xml' => ['size' => 100]]);
    expect(strlen($lying))->toBeLessThan(20 * 1024);
    expect(fn () => $reader->firstSheet($lying))->toThrow(XlsxReaderException::class, 'too large');
});

it('reads CSV UTF-8 with the byte-order mark, a semicolon separator and Arabic', function (): void {
    $rows = (new SpreadsheetRows)->read("\xEF\xBB\xBFname;name_ar;price\r\nShawarma;شاورما;\"1,200\"\r\n\r\nTea;شاي;0.300\r\n");

    expect($rows)->toBe([
        1 => ['name', 'name_ar', 'price'],
        2 => ['Shawarma', 'شاورما', '1,200'],
        4 => ['Tea', 'شاي', '0.300'],
    ]);
    expect(fn () => (new SpreadsheetRows)->read("name\n\xC8\xE5\xE1"))->toThrow(XlsxReaderException::class);
});
