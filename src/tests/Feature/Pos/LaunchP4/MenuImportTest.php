<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B6 — menu import and export (owner decision 5): a downloadable
 * .xlsx template, an .xlsx or CSV upload, a preview (new / update / error per
 * row with messages), and a save in one transaction. Matching is by SKU, else
 * by exact name (no duplicates on a second upload); an option creates
 * missing categories. The export uses the same columns. Plus L9 and the BOM
 * on the old CSV endpoint. Before: no template, preview, commit or export
 * endpoint; the old CSV import refused Excel's UTF-8 BOM header.
 */

use App\Enums\MerchantRole;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Catalogue\MenuSheet;
use App\Support\Spreadsheet\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Shuchkin\SimpleXLSXGen;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** An .xlsx upload of these rows (first row = the column names). */
function p4MenuXlsx(array $rows, string $name = 'menu.xlsx'): UploadedFile
{
    $cells = array_map(static fn (array $row): array => array_map(static fn ($c): string => SimpleXLSXGen::raw((string) $c), $row), $rows);

    return UploadedFile::fake()->createWithContent($name, (string) SimpleXLSXGen::fromArray($cells));
}

function p4MenuCsv(string $csv, string $name = 'menu.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $csv);
}

it('offers an .xlsx template with the columns and English and Arabic instructions', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $res = $this->getJson('/api/products/import/template')->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and($res->headers->get('Content-Disposition'))->toContain('menu-template.xlsx');

    $rows = (new XlsxReader)->firstSheet((string) $res->getContent());
    expect($rows[1])->toBe(MenuSheet::COLUMNS)
        ->and($rows[2][0])->toBe('Latte')
        ->and($rows[2][1])->toBe('لاتيه');
    expect(MenuSheet::COLUMNS)->toBe(['name', 'name_ar', 'category', 'category_ar', 'price', 'delivery_price', 'sku', 'barcode', 'description', 'description_ar', 'status', 'show_on_qr', 'sold_in_store', 'sold_on_delivery', 'display_order']);
    expect(MenuSheet::instructions()[0])->toBe(['column', 'English', 'العربية']);
});

it('previews new, update, unchanged and error rows without writing anything', function (): void {
    $ctx = makeMerchantActor();
    $hot = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot drinks', 'name_ar' => 'مشروبات ساخنة']);
    p4Product($ctx['company'], 'Latte', '1.400', ['sku' => 'LAT-01', 'category_id' => $hot->id]);
    p4Product($ctx['company'], 'Mocha', '1.700', ['category_id' => $hot->id]);
    $before = DB::table('pos_products')->count();

    $res = $this->post('/api/products/import/preview', ['file' => p4MenuXlsx([
        ['name', 'category', 'price', 'sku', 'status', 'show_on_qr'],
        ['Latte', 'Hot drinks', '1.500', 'LAT-01', 'active', 'yes'],     // update (price)
        ['Mocha', 'hot drinks', '1.700', '', '', ''],                      // unchanged, matched by name
        ['Karak', 'مشروبات ساخنة', '0.300', 'KRK', 'active', 'no'],        // new (category by Arabic name?) — see below
        ['Croissant', 'Bakery', '0.900', '', '', ''],                      // error: category missing
        ['Water', '', '', '', '', ''],                                     // error: price required
        ['Juice', '', 'abc', '', 'maybe', 'perhaps'],                      // errors: price, status, yes/no
        ['Karak', '', '0.400', '', '', ''],                                // error: name twice in the file
    ])], ['Accept' => 'application/json'])->assertOk();

    $rows = collect($res->json('data.rows'))->keyBy('row');
    expect($rows[2]['action'])->toBe('update')->and($rows[2]['changes'])->toBe(['base_price'])
        ->and($rows[3]['action'])->toBe('unchanged')
        ->and($rows[5]['action'])->toBe('error')
        ->and(collect($rows[5]['issues'])->pluck('code')->all())->toBe(['category_missing'])
        ->and(collect($rows[6]['issues'])->pluck('code')->all())->toBe(['price_required'])
        ->and(collect($rows[7]['issues'])->pluck('code')->all())->toBe(['money_invalid', 'yes_no', 'status_invalid'])
        ->and(collect($rows[8]['issues'])->pluck('code')->all())->toContain('duplicate_name_in_file');
    // "مشروبات ساخنة" in the category column is not a category NAME; the
    // Arabic name is matched only through category_ar.
    expect($rows[4]['action'])->toBe('error');
    expect($res->json('data.summary'))->toMatchArray(['total' => 7, 'update' => 1, 'unchanged' => 1, 'error' => 5]);
    expect(DB::table('pos_products')->count())->toBe($before);
    expect($rows[2])->not->toHaveKey('attributes');
});

it('saves an import in one go: creates, updates, makes missing categories, never duplicates', function (): void {
    $ctx = makeMerchantActor();
    $existing = p4Product($ctx['company'], 'Latte', '1.400', ['sku' => 'LAT-01']);
    $file = static fn (): UploadedFile => p4MenuXlsx([
        ['name', 'name_ar', 'category', 'category_ar', 'price', 'delivery_price', 'sku', 'barcode', 'description', 'description_ar', 'status', 'show_on_qr', 'sold_in_store', 'sold_on_delivery', 'display_order'],
        ['Latte', 'لاتيه', 'Hot drinks', 'مشروبات ساخنة', '1.500', '1.800', 'LAT-01', '', '', '', 'active', 'yes', 'yes', 'yes', '1'],
        ['Chicken shawarma', 'شاورما دجاج', 'Sandwiches', 'سندويشات', '١٫٢٠٠', '', 'SHW-01', '6291234567890', 'With garlic', 'مع الثوم', 'inactive', 'no', 'yes', 'no', '2'],
    ]);

    $this->post('/api/products/import/commit', ['file' => $file(), 'create_categories' => '1'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.saved', true)
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.updated', 1)
        ->assertJsonPath('data.categories_created', 2);

    $latte = $existing->fresh();
    expect((string) $latte->base_price)->toBe('1.500')
        ->and((string) $latte->delivery_price)->toBe('1.800')
        ->and($latte->name_ar)->toBe('لاتيه');
    $shawarma = Product::query()->where('sku', 'SHW-01')->sole();
    expect((string) $shawarma->base_price)->toBe('1.200')
        ->and($shawarma->status->value)->toBe('inactive')
        ->and($shawarma->show_on_customer_tablet)->toBeFalse()
        ->and($shawarma->sold_on_delivery)->toBeFalse()
        ->and($shawarma->description_ar)->toBe('مع الثوم')
        ->and($shawarma->barcode)->toBe('6291234567890');
    $sandwiches = ProductCategory::query()->where('name', 'Sandwiches')->sole();
    expect($sandwiches->name_ar)->toBe('سندويشات')->and($shawarma->category_id)->toBe($sandwiches->id);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.import.committed', 'company_id' => $ctx['company']->id]);

    // The same file again: nothing new, nothing changed.
    $count = DB::table('pos_products')->count();
    $this->post('/api/products/import/commit', ['file' => $file(), 'create_categories' => '1'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.created', 0)
        ->assertJsonPath('data.updated', 0)
        ->assertJsonPath('data.unchanged', 2)
        ->assertJsonPath('data.categories_created', 0);
    expect(DB::table('pos_products')->count())->toBe($count);
});

it('saves nothing while a row has an error', function (): void {
    makeMerchantActor();

    $this->post('/api/products/import/commit', ['file' => p4MenuXlsx([
        ['name', 'price', 'category'],
        ['Good', '1.000', ''],
        ['Bad', '-1', ''],
    ])], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'rows_have_errors')
        ->assertJsonPath('data.saved', false)
        ->assertJsonPath('data.preview.summary.error', 1);

    expect(Product::query()->where('name', 'Good')->exists())->toBeFalse();
});

it('rolls the whole import back when one product fails to save', function (): void {
    makeMerchantActor();
    Product::creating(static function (Product $product): void {
        if ($product->name === 'Boom') {
            throw new RuntimeException('boom');
        }
    });

    $this->post('/api/products/import/commit', ['file' => p4MenuXlsx([
        ['name', 'price'],
        ['First', '1.000'],
        ['Boom', '2.000'],
    ])], ['Accept' => 'application/json'])->assertStatus(500);

    expect(Product::query()->where('name', 'First')->exists())->toBeFalse();
});

it('refuses a name shared by two products or one that belongs to another SKU', function (): void {
    $ctx = makeMerchantActor();
    p4Product($ctx['company'], 'Tea', '0.200');
    p4Product($ctx['company'], 'Tea', '0.250');
    p4Product($ctx['company'], 'Coffee', '0.500', ['sku' => 'COF-1']);

    $rows = collect($this->post('/api/products/import/preview', ['file' => p4MenuXlsx([
        ['name', 'price', 'sku'],
        ['Tea', '0.300', ''],
        ['Coffee', '0.600', 'COF-2'],
    ])], ['Accept' => 'application/json'])->assertOk()->json('data.rows'))->keyBy('row');

    expect(collect($rows[2]['issues'])->pluck('code')->all())->toBe(['name_ambiguous'])
        ->and(collect($rows[3]['issues'])->pluck('code')->all())->toBe(['name_has_other_sku']);
});

it('accepts Excel CSV UTF-8 with its byte-order mark and Arabic text', function (): void {
    $ctx = makeMerchantActor();
    ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Juices', 'name_ar' => 'عصائر']);

    $csv = "\xEF\xBB\xBFname,name_ar,category_ar,price\r\nMango juice,عصير مانجو,عصائر,1.100\r\n";
    $this->post('/api/products/import/commit', ['file' => p4MenuCsv($csv)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.created', 1);

    $product = Product::query()->where('name', 'Mango juice')->sole();
    expect($product->name_ar)->toBe('عصير مانجو')
        ->and($product->category?->name)->toBe('Juices');

    $this->post('/api/products/import/preview', ['file' => p4MenuCsv("name,price\nTea,\xB0\xA1\n")], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'not_utf8');
});

it('explains a file it cannot read instead of failing', function (): void {
    makeMerchantActor();

    $this->post('/api/products/import/preview', ['file' => UploadedFile::fake()->createWithContent('menu.xlsx', "PK\x03\x04".str_repeat('broken', 20))], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'corrupt');
    $this->post('/api/products/import/preview', ['file' => p4MenuXlsx([['title', 'cost'], ['Tea', '1']])], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'no_name_column');
});

it('exports the menu in the same columns, as .xlsx or CSV, and re-imports as unchanged', function (): void {
    $ctx = makeMerchantActor();
    $cat = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot drinks', 'name_ar' => 'مشروبات ساخنة']);
    p4Product($ctx['company'], 'Latte', '1.500', ['sku' => 'LAT-01', 'category_id' => $cat->id, 'name_ar' => 'لاتيه', 'delivery_price' => '1.800', 'sold_on_delivery' => false]);
    p4Product($ctx['company'], 'Cup', '0', ['is_internal' => true, 'stock_mode' => 'unit']);

    $xlsx = $this->getJson('/api/products/export?format=xlsx')->assertOk();
    $rows = (new XlsxReader)->firstSheet((string) $xlsx->getContent());
    expect($rows[1])->toBe(MenuSheet::COLUMNS)
        ->and(count($rows))->toBe(2)
        ->and($rows[2])->toBe(['Latte', 'لاتيه', 'Hot drinks', 'مشروبات ساخنة', '1.500', '1.800', 'LAT-01', '', '', '', 'active', 'yes', 'yes', 'no', '0']);

    $csv = (string) $this->getJson('/api/products/export?format=csv')->assertOk()->getContent();
    expect($csv)->toStartWith("\xEF\xBB\xBFname,name_ar,category,category_ar,price");
    expect($csv)->toContain('Latte,لاتيه,"Hot drinks",');

    $this->post('/api/products/import/preview', ['file' => p4MenuCsv($csv)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.summary.unchanged', 1)
        ->assertJsonPath('data.summary.error', 0);
    $this->post('/api/products/import/preview', ['file' => UploadedFile::fake()->createWithContent('menu.xlsx', (string) $xlsx->getContent())], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.summary.unchanged', 1);
});

it('lets catalogue viewers download but only managers import', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/products/export')->assertOk();
    $this->post('/api/products/import/preview', ['file' => p4MenuXlsx([['name', 'price'], ['Tea', '1']])], ['Accept' => 'application/json'])->assertForbidden();
    $this->post('/api/products/import/commit', ['file' => p4MenuXlsx([['name', 'price'], ['Tea', '1']])], ['Accept' => 'application/json'])->assertForbidden();
});

it('fixes the old CSV import: the byte-order mark and the 500-character image link (L9)', function (): void {
    makeMerchantActor();
    $long = 'https://example.com/'.str_repeat('a', 490).'.jpg';

    $res = $this->post('/api/products/import', [
        'file' => p4MenuCsv("\xEF\xBB\xBFname,base_price,image_url\nTea,0.300,\nCake,1.000,{$long}\n", 'old.csv'),
    ], ['Accept' => 'application/json'])->assertOk();

    expect($res->json('data.created'))->toBe(1)
        ->and($res->json('data.failed'))->toBe(1);
    expect(Product::query()->where('name', 'Tea')->exists())->toBeTrue()
        ->and(Product::query()->where('name', 'Cake')->exists())->toBeFalse();
});
