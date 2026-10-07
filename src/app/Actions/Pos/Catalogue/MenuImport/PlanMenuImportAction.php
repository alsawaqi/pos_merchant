<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue\MenuImport;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Catalogue\MealMains;
use App\Support\Catalogue\MenuSheet;
use App\Support\MerchantTenantContext;
use App\Support\Spreadsheet\XlsxReaderException;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P4 B6 — the import preview (owner decision 5): what each row of the
 * menu file would do, without writing anything.
 *
 * Every row becomes 'new', 'update', 'unchanged' or 'error', with its issues
 * as stable codes (the portal shows them in English or Arabic):
 *   - matching: by SKU, else by the exact name, so a second upload updates
 *     instead of duplicating; a name shared by several products, or matching
 *     a product with another SKU, is an error;
 *   - within the file: a SKU, barcode or name used twice is an error;
 *   - categories: by name (or Arabic name); a missing one is an error, or a
 *     note "will be created" when the option is ticked;
 *   - cells: required name (and price for a new product), money with up to 3
 *     decimals, yes/no, active/inactive, display order 0..999, text lengths;
 *   - meals (combo fix order 2, C-15): a new product, or a category move,
 *     that would put a main in two active, on-sale meals ('meal_clash').
 * On an update a blank cell leaves the field as it is; only the fields that
 * change are listed.
 *
 * The result feeds both the preview response and {@see CommitMenuImportAction}
 * (which plans again from the same file, never from the browser's copy).
 */
final readonly class PlanMenuImportAction
{
    public const MAX_DATA_ROWS = 2000;

    private const LIMITS = [
        'name' => 191, 'name_ar' => 191, 'category' => 191, 'category_ar' => 191,
        'sku' => 64, 'barcode' => 64, 'description' => 1000, 'description_ar' => 1000,
    ];

    public function __construct(private MerchantTenantContext $tenant) {}

    /**
     * @param  array<int, list<string>>  $sheet  row number => cells
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, new_categories: list<array{name: string, name_ar: ?string}>}
     */
    public function handle(array $sheet, bool $createCategories): array
    {
        $companyId = $this->tenant->requiredId();
        if ($sheet === []) {
            throw new XlsxReaderException('empty', 'The file has no rows.');
        }
        $headerRow = array_key_first($sheet);
        $map = MenuSheet::headerMap($sheet[$headerRow]);
        if (! isset($map['name'])) {
            throw new XlsxReaderException('no_name_column', "The first row must hold the column names, with a 'name' column. Use the template.");
        }
        unset($sheet[$headerRow]);
        if (count($sheet) > self::MAX_DATA_ROWS) {
            throw new XlsxReaderException('too_many_rows', 'The file has more than '.self::MAX_DATA_ROWS.' products.');
        }

        $catalogue = $this->catalogue($companyId);
        $meals = MealMains::rivals($companyId, null, null, null);
        // LAUNCH review add-on (A4, A5) — SKUs are unique across ingredients and
        // products (case-insensitive), barcodes across the item barcodes too.
        $ingredientSkus = DB::table('pos_ingredients')
            ->where('company_id', $companyId)->whereNull('deleted_at')->whereNotNull('sku')->pluck('sku')
            ->mapWithKeys(static fn ($sku): array => [mb_strtolower((string) $sku) => true])->all();
        $itemBarcodes = DB::table('pos_item_barcodes as b')
            ->leftJoin('pos_ingredients as i', 'i.id', '=', 'b.ingredient_id')
            ->leftJoin('pos_products as p', 'p.id', '=', 'b.product_id')
            ->where('b.company_id', $companyId)->whereNull('b.deleted_at')
            // Fix order B-1 (L3, M2) — case-insensitive, and never a deleted item's code.
            ->where(static function ($q): void {
                $q->where(static fn ($w) => $w->whereNotNull('b.ingredient_id')->whereNull('i.deleted_at'))
                    ->orWhere(static fn ($w) => $w->whereNotNull('b.product_id')->whereNull('p.deleted_at'));
            })
            ->get(['b.barcode', 'i.name as ingredient_name', 'p.name as product_name'])
            ->mapWithKeys(static fn ($row): array => [mb_strtolower((string) $row->barcode) => (string) ($row->ingredient_name ?? $row->product_name)])->all();
        $seenSku = [];
        $seenBarcode = [];
        $seenName = [];
        $newCategories = [];
        $rows = [];

        foreach ($sheet as $number => $cells) {
            $cell = static fn (string $column): ?string => isset($map[$column]) ? MenuSheet::text((string) ($cells[$map[$column]] ?? '')) : null;
            $issues = [];
            $error = static function (string $code, ?string $field = null, array $params = []) use (&$issues): void {
                $issues[] = ['code' => $code, 'field' => $field, 'params' => $params, 'level' => 'error'];
            };

            $name = (string) $cell('name');
            if ($name === '') {
                $error('name_required', 'name');
            }
            foreach (self::LIMITS as $field => $max) {
                $value = $cell($field);
                if ($value !== null && mb_strlen($value) > $max) {
                    $error('too_long', $field, ['max' => $max]);
                }
            }

            $values = [];
            foreach (['price' => 'base_price', 'delivery_price' => 'delivery_price'] as $column => $attribute) {
                $raw = $cell($column);
                if ($raw === null || $raw === '') {
                    continue;
                }
                [$money, $code] = MenuSheet::money($raw);
                if ($code !== null) {
                    $error($code, $column);
                } else {
                    $values[$attribute] = $money;
                }
            }
            foreach (['show_on_qr' => 'show_on_customer_tablet', 'sold_in_store' => 'sold_in_store', 'sold_on_delivery' => 'sold_on_delivery'] as $column => $attribute) {
                $raw = $cell($column);
                if ($raw === null || $raw === '') {
                    continue;
                }
                $bool = MenuSheet::yesNo($raw);
                if ($bool === null) {
                    $error('yes_no', $column);
                } else {
                    $values[$attribute] = $bool;
                }
            }
            $rawStatus = $cell('status');
            if ($rawStatus !== null && $rawStatus !== '') {
                $status = MenuSheet::status($rawStatus);
                if ($status === null) {
                    $error('status_invalid', 'status');
                } else {
                    $values['status'] = $status;
                }
            }
            $rawOrder = $cell('display_order');
            if ($rawOrder !== null && $rawOrder !== '') {
                $order = MenuSheet::order($rawOrder);
                if ($order === null) {
                    $error('order_invalid', 'display_order');
                } else {
                    $values['display_order'] = $order;
                }
            }
            foreach (['name_ar', 'description', 'description_ar'] as $field) {
                $value = $cell($field);
                if ($value !== null && $value !== '') {
                    $values[$field] = $value;
                }
            }
            $sku = (string) $cell('sku');
            $barcode = (string) $cell('barcode');

            // Duplicates inside the file.
            if ($sku !== '') {
                if (isset($seenSku[mb_strtolower($sku)])) {
                    $error('duplicate_sku_in_file', 'sku', ['row' => $seenSku[mb_strtolower($sku)]]);
                }
                $seenSku[mb_strtolower($sku)] ??= $number;
            }
            if ($barcode !== '') {
                if (isset($seenBarcode[mb_strtolower($barcode)])) {
                    $error('duplicate_barcode_in_file', 'barcode', ['row' => $seenBarcode[mb_strtolower($barcode)]]);
                }
                $seenBarcode[mb_strtolower($barcode)] ??= $number;
            }
            if ($name !== '') {
                if (isset($seenName[$name])) {
                    $error('duplicate_name_in_file', 'name', ['row' => $seenName[$name]]);
                }
                $seenName[$name] ??= $number;
            }

            // Match: SKU, else the exact name.
            $match = null;
            if ($sku !== '' && isset($catalogue['bySku'][mb_strtolower($sku)])) {
                $candidate = $catalogue['bySku'][mb_strtolower($sku)];
                if ($candidate['deleted']) {
                    $error('sku_deleted_product', 'sku');
                } elseif ($candidate['internal']) {
                    $error('sku_physical_item', 'sku');
                } else {
                    $match = $candidate;
                }
            } elseif ($name !== '') {
                $byName = $catalogue['byName'][$name] ?? [];
                if (count($byName) > 1) {
                    $error('name_ambiguous', 'name', ['count' => count($byName)]);
                } elseif (count($byName) === 1) {
                    $candidate = $byName[0];
                    if ($sku !== '' && $candidate['sku'] !== null && $candidate['sku'] !== '' && mb_strtolower((string) $candidate['sku']) !== mb_strtolower($sku)) {
                        $error('name_has_other_sku', 'sku', ['sku' => $candidate['sku']]);
                    } else {
                        $match = $candidate;
                    }
                }
            }
            if ($sku !== '' && isset($ingredientSkus[mb_strtolower($sku)])) {
                // An ingredient's SKU (review add-on A4): not a menu product.
                $error('sku_physical_item', 'sku');
            } elseif ($sku !== '' && ($match === null || $match['sku'] === null || $match['sku'] === '')) {
                $values['sku'] = $sku;
            }
            if ($barcode !== '') {
                $owner = $catalogue['byBarcode'][mb_strtolower($barcode)] ?? null;
                if (isset($itemBarcodes[mb_strtolower($barcode)])) {
                    // A container / item barcode (review add-on A5).
                    $error('barcode_taken', 'barcode', ['product' => $itemBarcodes[mb_strtolower($barcode)]]);
                } elseif ($owner !== null && ($match === null || $owner['id'] !== $match['id'])) {
                    $error('barcode_taken', 'barcode', ['product' => $owner['name']]);
                } else {
                    $values['barcode'] = $barcode;
                }
            }

            // Category: by name, else by Arabic name.
            $categoryName = (string) $cell('category');
            $categoryAr = (string) $cell('category_ar');
            $categoryRef = null;
            if ($categoryName !== '' || $categoryAr !== '') {
                $found = ($categoryName !== '' ? ($catalogue['categories'][mb_strtolower($categoryName)] ?? null) : null)
                    ?? ($categoryAr !== '' ? ($catalogue['categoriesAr'][mb_strtolower($categoryAr)] ?? null) : null);
                if ($found !== null) {
                    $values['category_id'] = $found;
                } elseif ($categoryName === '') {
                    $error('category_name_needed', 'category');
                } elseif (isset($catalogue['deletedCategories'][mb_strtolower($categoryName)])) {
                    $error('category_deleted', 'category', ['name' => $categoryName]);
                } elseif ($createCategories) {
                    $key = mb_strtolower($categoryName);
                    $newCategories[$key] ??= ['name' => $categoryName, 'name_ar' => $categoryAr !== '' ? $categoryAr : null];
                    $categoryRef = $key;
                    $issues[] = ['code' => 'category_will_be_created', 'field' => 'category', 'params' => ['name' => $categoryName], 'level' => 'info'];
                } else {
                    $error('category_missing', 'category', ['name' => $categoryName]);
                }
            }

            $isNew = $match === null;
            if ($isNew && ! array_key_exists('base_price', $values) && ! self::hasError($issues, 'price')) {
                $error('price_required', 'price');
            }

            $changes = [];
            if (! $isNew) {
                $current = $match['values'];
                if ($name !== '' && $name !== $match['name']) {
                    $values['name'] = $name;
                }
                foreach ($values as $field => $value) {
                    if (! array_key_exists($field, $current) || $current[$field] !== $value) {
                        $changes[] = $field;
                    }
                }
                $values = array_intersect_key($values, array_flip($changes));
                if ($categoryRef !== null) {
                    $changes[] = 'category_id';
                }
            } else {
                $values['name'] = $name;
            }

            // Combo fix order 2 (C-15) — a standard product landing in a
            // category two overlapping active meals take mains from. A new
            // category is in no meal yet.
            $targetCategory = ($isNew || in_array('category_id', $changes, true)) ? ($values['category_id'] ?? null) : null;
            if ($targetCategory !== null && ($isNew || (! $match['internal'] && ! $match['combo']))) {
                $pair = MealMains::clashPair($meals, $isNew ? null : $match['id'], (int) $targetCategory);
                if ($pair !== null) {
                    $error('meal_clash', 'category', ['meal' => $pair[0], 'other_meal' => $pair[1]]);
                }
            }

            $hasError = self::hasError($issues);
            $rows[] = [
                'row' => $number,
                'action' => $hasError ? 'error' : ($isNew ? 'new' : ($changes === [] ? 'unchanged' : 'update')),
                'name' => $name,
                'category' => $categoryName !== '' ? $categoryName : ($categoryAr !== '' ? $categoryAr : null),
                'price' => $values['base_price'] ?? ($match['values']['base_price'] ?? null),
                'sku' => $sku !== '' ? $sku : ($match['sku'] ?? null),
                'product_uuid' => $match['uuid'] ?? null,
                'changes' => array_values(array_unique($changes)),
                'issues' => $issues,
                // Internal (the commit applies these; the preview drops them).
                'attributes' => $values,
                'category_ref' => $categoryRef,
                'product_id' => $match['id'] ?? null,
            ];
        }

        // Only categories still needed by a row that will be saved.
        $needed = [];
        foreach ($rows as $row) {
            if ($row['category_ref'] !== null && in_array($row['action'], ['new', 'update'], true)) {
                $needed[$row['category_ref']] = $newCategories[$row['category_ref']];
            }
        }

        $summary = ['total' => count($rows), 'new' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0, 'new_categories' => count($needed)];
        foreach ($rows as $row) {
            $summary[$row['action']]++;
        }

        return ['rows' => $rows, 'summary' => $summary, 'new_categories' => array_values($needed)];
    }

    /**
     * @param  list<array{code: string, field: ?string, params: array<string, mixed>, level: string}>  $issues
     */
    private static function hasError(array $issues, ?string $field = null): bool
    {
        foreach ($issues as $issue) {
            if ($issue['level'] === 'error' && ($field === null || $issue['field'] === $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The company's products and categories, indexed for matching.
     *
     * @return array{bySku: array<string, array<string, mixed>>, byName: array<string, list<array<string, mixed>>>, byBarcode: array<string, array<string, mixed>>, categories: array<string, int>, categoriesAr: array<string, int>, deletedCategories: array<string, true>}
     */
    private function catalogue(int $companyId): array
    {
        $bySku = [];
        $byName = [];
        $byBarcode = [];
        $products = Product::query()->withTrashed()->where('company_id', $companyId)->get();
        foreach ($products as $p) {
            $entry = [
                'id' => (int) $p->id,
                'uuid' => (string) $p->uuid,
                'name' => (string) $p->name,
                'sku' => $p->sku,
                'internal' => (bool) $p->is_internal,
                'combo' => $p->isCombo(),
                'deleted' => $p->trashed(),
                'values' => [
                    'name' => (string) $p->name,
                    'name_ar' => $p->name_ar,
                    'category_id' => $p->category_id !== null ? (int) $p->category_id : null,
                    'base_price' => (string) $p->base_price,
                    'delivery_price' => $p->delivery_price !== null ? (string) $p->delivery_price : null,
                    'sku' => $p->sku,
                    'barcode' => $p->barcode,
                    'description' => $p->description,
                    'description_ar' => $p->description_ar,
                    'status' => $p->status?->value ?? 'active',
                    'show_on_customer_tablet' => (bool) $p->show_on_customer_tablet,
                    'sold_in_store' => (bool) ($p->sold_in_store ?? true),
                    'sold_on_delivery' => (bool) ($p->sold_on_delivery ?? true),
                    'display_order' => (int) $p->display_order,
                ],
            ];
            if ($p->sku !== null && $p->sku !== '') {
                $bySku[mb_strtolower((string) $p->sku)] = $entry;
            }
            if ($p->barcode !== null && $p->barcode !== '') {
                $byBarcode[mb_strtolower((string) $p->barcode)] = $entry;
            }
            if (! $entry['deleted'] && ! $entry['internal']) {
                $byName[(string) $p->name][] = $entry;
            }
        }

        $categories = [];
        $categoriesAr = [];
        $deleted = [];
        foreach (ProductCategory::query()->withTrashed()->where('company_id', $companyId)->get(['id', 'name', 'name_ar', 'deleted_at']) as $c) {
            if ($c->trashed()) {
                $deleted[mb_strtolower(trim((string) $c->name))] = true;

                continue;
            }
            $categories[mb_strtolower(trim((string) $c->name))] ??= (int) $c->id;
            if ($c->name_ar !== null && trim((string) $c->name_ar) !== '') {
                $categoriesAr[mb_strtolower(trim((string) $c->name_ar))] ??= (int) $c->id;
            }
        }

        return [
            'bySku' => $bySku,
            'byName' => $byName,
            'byBarcode' => $byBarcode,
            'categories' => $categories,
            'categoriesAr' => $categoriesAr,
            'deletedCategories' => $deleted,
        ];
    }
}
