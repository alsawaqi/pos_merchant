<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\Product;
use Shuchkin\SimpleXLSXGen;

/**
 * LAUNCH-P4 B6 — the menu spreadsheet (owner decision 5): one sheet, one
 * product per row, these columns (the same for the template, the import and
 * the export):
 *
 *   name, name_ar, category, category_ar, price, delivery_price, sku, barcode,
 *   description, description_ar, status, show_on_qr, sold_in_store,
 *   sold_on_delivery, display_order
 *
 * Plus the cell parsers the importer uses (money, yes/no, status, whole
 * numbers), tolerant of what spreadsheets produce: Arabic-Indic digits, a
 * decimal comma, float noise (1.2000000000000002), TRUE/FALSE, نعم/لا.
 */
final class MenuSheet
{
    public const COLUMNS = [
        'name', 'name_ar', 'category', 'category_ar', 'price', 'delivery_price', 'sku', 'barcode',
        'description', 'description_ar', 'status', 'show_on_qr', 'sold_in_store', 'sold_on_delivery',
        'display_order',
    ];

    /** Older or friendlier header spellings. */
    private const ALIASES = [
        'base_price' => 'price',
        'show_on_customer_tablet' => 'show_on_qr',
        'qr' => 'show_on_qr',
        'qr_menu' => 'show_on_qr',
        'in_store' => 'sold_in_store',
        'delivery' => 'sold_on_delivery',
        'arabic_name' => 'name_ar',
    ];

    /** "Price " / "PRICE" / "\u{FEFF}name" / "show on qr" → the column key. */
    public static function normalizeHeader(string $cell): string
    {
        $cell = str_replace("\u{FEFF}", '', $cell);
        $key = strtolower(trim($cell));
        $key = (string) preg_replace('/[\s\-]+/', '_', $key);

        return self::ALIASES[$key] ?? $key;
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int> column key => cell index (first one wins)
     */
    public static function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $cell) {
            $key = self::normalizeHeader((string) $cell);
            if (in_array($key, self::COLUMNS, true) && ! isset($map[$key])) {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /** Text as typed; a leading apostrophe that guarded a formula is dropped. */
    public static function text(string $value): string
    {
        $value = trim($value);
        if (strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@'], true)) {
            $value = substr($value, 1);
        }

        return $value;
    }

    /** Arabic-Indic / Persian digits and the Arabic decimal mark → ASCII. */
    public static function asciiDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.',
        ]);
    }

    /**
     * An OMR amount as a 3-decimal string, or an error code:
     * 'money_invalid' | 'money_decimals' | 'money_range'.
     *
     * @return array{0: ?string, 1: ?string} [value, error]
     */
    public static function money(string $raw): array
    {
        $value = str_replace(' ', '', self::asciiDigits(trim($raw)));
        if (substr_count($value, ',') === 1 && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1 && preg_match('/^\d+(\.\d+)?[eE][-+]?\d+$/', $value) !== 1) {
            return [null, 'money_invalid'];
        }
        $float = (float) $value;
        $rounded = round($float, 3);
        if (abs($float - $rounded) > 0.0000005) {
            return [null, 'money_decimals'];
        }
        if ($rounded > 999999.999) {
            return [null, 'money_range'];
        }

        return [number_format($rounded, 3, '.', ''), null];
    }

    /** yes / no → bool; null = not a yes/no answer. */
    public static function yesNo(string $raw): ?bool
    {
        $value = mb_strtolower(trim(self::asciiDigits($raw)));

        return match ($value) {
            'yes', 'y', 'true', '1', 'on', 'نعم', 'صح' => true,
            'no', 'n', 'false', '0', 'off', 'لا', 'خطأ' => false,
            default => null,
        };
    }

    /** active / inactive (or Arabic) → the status value; null = unknown. */
    public static function status(string $raw): ?string
    {
        $value = mb_strtolower(trim($raw));

        return match ($value) {
            'active', 'on', 'yes', 'نشط', 'فعال', 'مفعل' => 'active',
            'inactive', 'off', 'no', 'غير نشط', 'متوقف', 'غير مفعل' => 'inactive',
            default => null,
        };
    }

    /** A whole number 0..999 (display order); null = invalid. */
    public static function order(string $raw): ?int
    {
        $value = self::asciiDigits(trim($raw));
        if (preg_match('/^\d+(\.0+)?$/', $value) !== 1) {
            return null;
        }
        $number = (int) $value;

        return $number <= 999 ? $number : null;
    }

    // ---- template / export ---------------------------------------------------

    /**
     * @return list<list<string>>
     */
    public static function templateRows(): array
    {
        return [
            self::COLUMNS,
            ['Latte', 'لاتيه', 'Hot drinks', 'مشروبات ساخنة', '1.500', '1.800', 'LAT-01', '', 'Espresso with steamed milk', 'إسبريسو مع حليب مبخر', 'active', 'yes', 'yes', 'yes', '1'],
            ['Chicken shawarma', 'شاورما دجاج', 'Sandwiches', 'سندويشات', '1.200', '', 'SHW-01', '6291234567890', '', '', 'active', 'yes', 'yes', 'no', '2'],
        ];
    }

    /**
     * The second sheet of the template: what each column means, in English and
     * Arabic.
     *
     * @return list<list<string>>
     */
    public static function instructions(): array
    {
        return [
            ['column', 'English', 'العربية'],
            ['name', 'Required. The product name. A row matches an existing product by its SKU, else by this exact name; otherwise a new product is added.', 'مطلوب. اسم المنتج. يُطابَق السطر مع منتج موجود برمز SKU، وإلا بهذا الاسم تماماً؛ وإلا يُضاف منتج جديد.'],
            ['name_ar', 'Arabic name.', 'الاسم بالعربية.'],
            ['category', 'Category name. It must exist, unless you tick "Create missing categories".', 'اسم الفئة. يجب أن تكون موجودة، إلا إذا حددت "إنشاء الفئات الناقصة".'],
            ['category_ar', 'Arabic category name (used for a new category, or to find one).', 'اسم الفئة بالعربية (لفئة جديدة، أو للبحث عنها).'],
            ['price', 'Required for a new product. In-store and QR price in OMR, up to 3 decimals.', 'مطلوب لمنتج جديد. سعر المحل وقائمة QR بالريال، حتى 3 خانات عشرية.'],
            ['delivery_price', 'Optional delivery price in OMR. Blank = the price.', 'سعر التوصيل اختياري بالريال. فارغ = السعر.'],
            ['sku', 'Optional code, unique per product. Best way to update a product later.', 'رمز اختياري فريد لكل منتج. أفضل طريقة لتحديث المنتج لاحقاً.'],
            ['barcode', 'Optional barcode, unique per product.', 'باركود اختياري فريد لكل منتج.'],
            ['description', 'Optional description.', 'وصف اختياري.'],
            ['description_ar', 'Optional Arabic description.', 'وصف اختياري بالعربية.'],
            ['status', 'active or inactive. Blank = active for a new product.', 'active أو inactive. فارغ = active لمنتج جديد.'],
            ['show_on_qr', 'yes or no: shown on the QR menu.', 'yes أو no: يظهر في قائمة QR.'],
            ['sold_in_store', 'yes or no: sold on the till and handheld.', 'yes أو no: يُباع على نقطة البيع والجهاز المحمول.'],
            ['sold_on_delivery', 'yes or no: sold on delivery.', 'yes أو no: يُباع في التوصيل.'],
            ['display_order', 'Optional whole number 0 to 999: the order on the menu.', 'رقم صحيح اختياري من 0 إلى 999: الترتيب في القائمة.'],
            ['', 'When a product is updated, a blank cell leaves that field as it is. Photos, add-ons and combos are set in the portal.', 'عند تحديث منتج، الخلية الفارغة تترك الحقل كما هو. الصور والإضافات والكومبو تُضبط في البوابة.'],
        ];
    }

    /**
     * Every menu product of the company (not physical items), in menu order.
     *
     * @return list<list<string>>
     */
    public static function exportRows(int $companyId): array
    {
        $products = Product::query()
            ->where('pos_products.company_id', $companyId)
            ->where('pos_products.is_internal', false)
            ->leftJoin('pos_product_categories as c', 'c.id', '=', 'pos_products.category_id')
            ->orderByRaw('c.display_order IS NULL, c.display_order')
            ->orderBy('c.name')
            ->orderBy('pos_products.display_order')
            ->orderBy('pos_products.name')
            ->get(['pos_products.*', 'c.name as category_name', 'c.name_ar as category_name_ar']);

        $rows = [self::COLUMNS];
        foreach ($products as $p) {
            $rows[] = [
                (string) $p->name,
                (string) ($p->name_ar ?? ''),
                (string) ($p->getAttribute('category_name') ?? ''),
                (string) ($p->getAttribute('category_name_ar') ?? ''),
                (string) $p->base_price,
                $p->delivery_price !== null ? (string) $p->delivery_price : '',
                (string) ($p->sku ?? ''),
                (string) ($p->barcode ?? ''),
                (string) ($p->description ?? ''),
                (string) ($p->description_ar ?? ''),
                (string) ($p->status?->value ?? 'active'),
                $p->show_on_customer_tablet ? 'yes' : 'no',
                ($p->sold_in_store ?? true) ? 'yes' : 'no',
                ($p->sold_on_delivery ?? true) ? 'yes' : 'no',
                (string) (int) $p->display_order,
            ];
        }

        return $rows;
    }

    /**
     * An .xlsx of the rows (+ an optional second sheet). Text cells are raw
     * (no formula / markup interpretation); money keeps its 3 decimals.
     *
     * @param  list<list<string>>  $rows
     * @param  list<list<string>>|null  $second
     */
    public static function toXlsx(array $rows, string $sheetName, ?array $second = null, string $secondName = 'Instructions'): string
    {
        $moneyColumns = [array_search('price', self::COLUMNS, true), array_search('delivery_price', self::COLUMNS, true)];
        $typed = [];
        foreach ($rows as $r => $row) {
            $typed[] = array_map(static function (string $cell, int $index) use ($r, $moneyColumns): mixed {
                if ($r === 0) {
                    return '<b>'.htmlspecialchars($cell, ENT_QUOTES).'</b>';
                }
                if (in_array($index, $moneyColumns, true) && preg_match('/^\d{1,9}\.\d{3}$/', $cell) === 1) {
                    return '<style nf="0.000">'.$cell.'</style>';
                }

                return SimpleXLSXGen::raw($cell);
            }, $row, array_keys($row));
        }

        $xlsx = SimpleXLSXGen::fromArray($typed, $sheetName);
        if ($second !== null) {
            $xlsx->addSheet(array_map(static fn (array $row): array => array_map(static fn (string $c): string => SimpleXLSXGen::raw($c), $row), $second), $secondName);
        }

        return (string) $xlsx;
    }

    /**
     * A CSV (UTF-8 with a byte-order mark, so Excel shows Arabic). A text cell
     * that would start a formula gets a leading apostrophe.
     *
     * @param  list<list<string>>  $rows
     */
    public static function toCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }
        foreach ($rows as $row) {
            $safe = array_map(static function (string $c): string {
                $startsFormula = $c !== '' && (in_array($c[0], ['=', '+', '@'], true) || ($c[0] === '-' && ! is_numeric($c)));

                return $startsFormula ? "'".$c : $c;
            }, $row);
            fputcsv($stream, $safe, ',', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".$csv;
    }
}
