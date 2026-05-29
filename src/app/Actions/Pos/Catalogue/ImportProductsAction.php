<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Models\ProductCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 6b-import — bulk product creation from a CSV.
 *
 * Best-effort, row-by-row: every valid row is created (each in its own
 * transaction + audit log via CreateProductAction), and an invalid row is
 * reported with its errors rather than aborting the whole file. The merchant
 * uploads a spreadsheet exported to CSV; we hand back a per-row result they
 * can act on.
 *
 * A header row is required. Recognised columns (case-insensitive; extras are
 * ignored): name*, base_price*, category, sku, barcode, name_ar, description,
 * image_url, delivery_price, cost_price, tax_rate, display_order. `category`
 * is the category NAME — resolved to one of the company's categories (blank =
 * none, unknown name = row error) — because merchants think in names, not ids.
 */
final readonly class ImportProductsAction
{
    /** Hard cap on data rows per upload (timeout / abuse guard). */
    private const MAX_ROWS = 1000;

    /** Columns we read; anything else in the header is ignored. */
    private const COLUMNS = [
        'name', 'base_price', 'category', 'sku', 'barcode', 'name_ar',
        'description', 'image_url', 'delivery_price', 'cost_price',
        'tax_rate', 'display_order',
    ];

    public function __construct(
        private CreateProductAction $createProduct,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array{total: int, created: int, failed: int, rows: list<array<string, mixed>>}
     */
    public function handle(string $csv, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        [$header, $dataRows] = $this->parse($csv);
        $this->assertHeader($header);

        // The company's categories, keyed by lower-cased name for matching.
        $categoryIdsByName = ProductCategory::query()
            ->where('company_id', $companyId)
            ->pluck('id', 'name')
            ->mapWithKeys(static fn ($id, $name): array => [mb_strtolower(trim((string) $name)) => $id])
            ->all();

        $results = [];
        $created = 0;
        $failed = 0;

        foreach ($dataRows as [$lineNo, $values]) {
            $assoc = $this->mapRow($header, $values);
            $rowErrors = [];
            $attributes = $this->buildAttributes($assoc, $categoryIdsByName, $rowErrors);

            if ($rowErrors === []) {
                $validator = Validator::make($attributes, $this->rowRules());
                if ($validator->fails()) {
                    $rowErrors = $validator->errors()->all();
                }
            }

            if ($rowErrors !== []) {
                $failed++;
                $results[] = ['line' => $lineNo, 'status' => 'failed', 'name' => $assoc['name'] ?? null, 'errors' => $rowErrors];

                continue;
            }

            try {
                $product = $this->createProduct->handle($attributes, $actor);
                $created++;
                $results[] = ['line' => $lineNo, 'status' => 'created', 'name' => $product->name, 'product_id' => $product->id];
            } catch (Throwable $e) {
                $failed++;
                $results[] = ['line' => $lineNo, 'status' => 'failed', 'name' => $assoc['name'] ?? null, 'errors' => [$e->getMessage()]];
            }
        }

        return ['total' => count($dataRows), 'created' => $created, 'failed' => $failed, 'rows' => $results];
    }

    /**
     * Parse the CSV into a lower-cased header + the non-blank data rows
     * (each tagged with its 1-based line number for the result report).
     *
     * @return array{0: list<string>, 1: list<array{0: int, 1: list<string>}>}
     */
    private function parse(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream);
        if ($header === false || $header === null) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'The CSV file is empty.']);
        }
        $header = array_map(static fn ($h): string => mb_strtolower(trim((string) $h)), $header);

        $rows = [];
        $lineNo = 1; // the header occupies line 1
        while (($values = fgetcsv($stream)) !== false) {
            $lineNo++;
            if ($this->isBlank($values)) {
                continue;
            }
            $rows[] = [$lineNo, $values];
            if (count($rows) > self::MAX_ROWS) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'The CSV exceeds the '.self::MAX_ROWS.'-row limit.']);
            }
        }
        fclose($stream);

        return [$header, $rows];
    }

    /**
     * @param  list<string>  $header
     */
    private function assertHeader(array $header): void
    {
        foreach (['name', 'base_price'] as $required) {
            if (! in_array($required, $header, true)) {
                throw ValidationException::withMessages([
                    'file' => "The CSV must have a '{$required}' column header.",
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $values
     * @return array<string, string>
     */
    private function mapRow(array $header, array $values): array
    {
        $assoc = [];
        foreach ($header as $i => $col) {
            if (in_array($col, self::COLUMNS, true)) {
                $assoc[$col] = trim((string) ($values[$i] ?? ''));
            }
        }

        return $assoc;
    }

    /**
     * @param  array<string, string>  $assoc
     * @param  array<string, int>  $categoryIdsByName
     * @param  list<string>  $rowErrors
     * @return array<string, mixed>
     */
    private function buildAttributes(array $assoc, array $categoryIdsByName, array &$rowErrors): array
    {
        $attributes = [
            'name' => $this->blankToNull($assoc['name'] ?? ''),
            'base_price' => $this->blankToNull($assoc['base_price'] ?? ''),
            'sku' => $this->blankToNull($assoc['sku'] ?? ''),
            'barcode' => $this->blankToNull($assoc['barcode'] ?? ''),
            'name_ar' => $this->blankToNull($assoc['name_ar'] ?? ''),
            'description' => $this->blankToNull($assoc['description'] ?? ''),
            'image_url' => $this->blankToNull($assoc['image_url'] ?? ''),
            'delivery_price' => $this->blankToNull($assoc['delivery_price'] ?? ''),
            'cost_price' => $this->blankToNull($assoc['cost_price'] ?? ''),
            'tax_rate' => $this->blankToNull($assoc['tax_rate'] ?? ''),
            'display_order' => $this->blankToNull($assoc['display_order'] ?? ''),
            'category_id' => null,
        ];

        $categoryName = trim((string) ($assoc['category'] ?? ''));
        if ($categoryName !== '') {
            $key = mb_strtolower($categoryName);
            if (array_key_exists($key, $categoryIdsByName)) {
                $attributes['category_id'] = $categoryIdsByName[$key];
            } else {
                $rowErrors[] = "Unknown category '{$categoryName}'.";
            }
        }

        return $attributes;
    }

    /**
     * Per-row rules — mirrors StoreProductRequest. category_id is already
     * resolved from the `category` name column, scoped to the company.
     *
     * @return array<string, list<string>>
     */
    private function rowRules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'exists:pos_product_categories,id'],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image_url' => ['nullable', 'url', 'max:1000'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:999999.999'],
            'delivery_price' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string|null>  $values
     */
    private function isBlank(array $values): bool
    {
        foreach ($values as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }
}
