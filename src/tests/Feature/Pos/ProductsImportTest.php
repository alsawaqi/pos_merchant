<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\post;

uses(RefreshDatabase::class);

/**
 * Phase 6b-import — CSV product import coverage.
 *
 * Best-effort row-by-row: valid rows are created, invalid rows reported.
 * Category is resolved by name, scoped to the actor's company. Gated by
 * catalogue.manage.
 */
function importCsv(string $csv): TestResponse
{
    $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

    // Multipart upload, but ask for JSON so validation/permission failures
    // render as 422/403 JSON rather than a redirect.
    return post('/api/products/import', ['file' => $file], ['Accept' => 'application/json']);
}

it('bulk-creates products from a CSV', function (): void {
    $ctx = makeMerchantActor();

    $csv = "name,base_price,sku\nCappuccino,1.500,CAP\nLatte,1.700,LAT\n";

    $res = importCsv($csv)->assertOk();

    expect($res->json('data.total'))->toBe(2);
    expect($res->json('data.created'))->toBe(2);
    expect($res->json('data.failed'))->toBe(0);

    $this->assertDatabaseHas('pos_products', ['name' => 'Cappuccino', 'company_id' => $ctx['company']->id, 'sku' => 'CAP']);
    $this->assertDatabaseHas('pos_products', ['name' => 'Latte', 'company_id' => $ctx['company']->id]);
});

it('reports per-row errors without aborting the valid rows', function (): void {
    makeMerchantActor();

    // line 2 valid; line 3 missing name; line 4 non-numeric price.
    $csv = "name,base_price\nMocha,2.000\n,1.000\nTea,abc\n";

    $res = importCsv($csv)->assertOk();

    expect($res->json('data.total'))->toBe(3);
    expect($res->json('data.created'))->toBe(1);
    expect($res->json('data.failed'))->toBe(2);

    $rows = collect($res->json('data.rows'));
    expect($rows->firstWhere('line', 2)['status'])->toBe('created');
    expect($rows->firstWhere('line', 3)['status'])->toBe('failed');
    expect($rows->firstWhere('line', 4)['status'])->toBe('failed');

    $this->assertDatabaseHas('pos_products', ['name' => 'Mocha']);
    $this->assertDatabaseMissing('pos_products', ['name' => 'Tea']);
});

it('resolves the category by name (case-insensitive) within the company', function (): void {
    $ctx = makeMerchantActor();
    $cat = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Hot Drinks']);

    $csv = "name,base_price,category\nFlat White,1.600,hot drinks\nGhost,1.000,Nonexistent\n";

    $res = importCsv($csv)->assertOk();

    expect($res->json('data.created'))->toBe(1);
    expect($res->json('data.failed'))->toBe(1);

    $this->assertDatabaseHas('pos_products', ['name' => 'Flat White', 'category_id' => $cat->id]);
    $rows = collect($res->json('data.rows'));
    expect($rows->firstWhere('name', 'Ghost')['errors'][0])->toContain('Unknown category');
});

it('does not resolve a category from another company', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    ProductCategory::factory()->for($other, 'company')->create(['name' => 'Foreign']);

    $csv = "name,base_price,category\nX,1.000,Foreign\n";

    $res = importCsv($csv)->assertOk();

    expect($res->json('data.failed'))->toBe(1);
    $this->assertDatabaseMissing('pos_products', ['name' => 'X']);
});

it('rejects a CSV missing the required headers', function (): void {
    makeMerchantActor();

    $csv = "title,price\nNope,1.000\n";

    importCsv($csv)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('requires catalogue.manage permission', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value); // read-only actor (no catalogue.manage)

    importCsv("name,base_price\nNope,1.000\n")->assertForbidden();
});
