<?php

declare(strict_types=1);

/**
 * LAUNCH combo add-on, Part A fix order 2 (LAUNCH-COMBO_A_FIX_ORDER_2.md):
 *
 *   C-15 a menu import row that would put a main in two meals is flagged in
 *        the preview and refused on commit with a 422 naming the row (never
 *        a 500)
 *   C-20 the Meals page works out the clashes once per request
 */

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Shuchkin\SimpleXLSXGen;

uses(RefreshDatabase::class);

require_once __DIR__.'/../LaunchP4/helpers.php';

/** An .xlsx upload of these rows (first row = the column names). */
function f2MenuXlsx(array $rows): UploadedFile
{
    $cells = array_map(static fn (array $row): array => array_map(static fn ($c): string => SimpleXLSXGen::raw((string) $c), $row), $rows);

    return UploadedFile::fake()->createWithContent('menu.xlsx', (string) SimpleXLSXGen::fromArray($cells));
}

/**
 * Burgers (Beef, Chicken) in two active meals, each unticking the other's
 * main: no clash today, but a NEW burger would be in both.
 *
 * @return array<string, mixed>
 */
function f2TwoMeals(array $ctx): array
{
    $burgers = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Burgers']);
    $sides = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Sides']);
    $beef = p4Product($ctx['company'], 'Beef burger', '2.000', ['category_id' => $burgers->id]);
    $chicken = p4Product($ctx['company'], 'Chicken burger', '1.800', ['category_id' => $burgers->id]);
    $fries = p4Product($ctx['company'], 'Fries', '1.000', ['category_id' => $sides->id, 'sku' => 'FRY']);
    $meal = static fn (string $name, string $untick): array => ['name' => $name, 'name_ar' => null, 'meal_price' => '1.200',
        'category_ids' => [$burgers->id], 'excluded_product_uuids' => [$untick],
        'lines' => [['kind' => 'fixed', 'product_uuid' => $fries->uuid, 'quantity' => 1, 'upgrades' => []]]];
    test()->postJson('/api/meals', $meal('Beef meal', $chicken->uuid))->assertCreated();
    test()->postJson('/api/meals', $meal('Chicken meal', $beef->uuid))->assertCreated();

    return ['burgers' => $burgers, 'sides' => $sides, 'fries' => $fries];
}

it('C-15: the import preview flags a row that would put a main in two meals', function (): void {
    $ctx = makeMerchantActor();
    f2TwoMeals($ctx);

    $rows = collect($this->post('/api/products/import/preview', ['file' => f2MenuXlsx([
        ['name', 'category', 'price', 'sku'],
        ['Mushroom burger', 'Burgers', '2.200', ''],   // new, in both meals
        ['Fries', 'Burgers', '', 'FRY'],               // moved into Burgers: in both meals
        ['Onion rings', 'Sides', '0.900', ''],         // fine
    ])], ['Accept' => 'application/json'])->assertOk()->json('data.rows'))->keyBy('row');

    foreach ([2, 3] as $row) {
        expect($rows[$row]['action'])->toBe('error');
        $issue = collect($rows[$row]['issues'])->firstWhere('code', 'meal_clash');
        expect($issue)->not->toBeNull()
            ->and($issue['field'])->toBe('category')
            ->and($issue['params'])->toBe(['meal' => 'Beef meal', 'other_meal' => 'Chicken meal']);
    }
    expect($rows[4]['action'])->toBe('new');
});

it('C-15: commit refuses the clash with a 422 naming the row, never a 500, and saves nothing', function (): void {
    $ctx = makeMerchantActor();
    $m = f2TwoMeals($ctx);
    $before = Product::query()->count();

    $res = $this->post('/api/products/import/commit', ['file' => f2MenuXlsx([
        ['name', 'category', 'price'],
        ['Onion rings', 'Sides', '0.900'],
        ['Mushroom burger', 'Burgers', '2.200'],
    ])], ['Accept' => 'application/json']);

    $res->assertStatus(422)->assertJsonPath('reason', 'rows_have_errors')->assertJsonPath('data.saved', false);
    $row = collect($res->json('data.preview.rows'))->firstWhere('row', 3);
    expect($row['name'])->toBe('Mushroom burger')->and(collect($row['issues'])->pluck('code')->all())->toContain('meal_clash');
    expect(Product::query()->count())->toBe($before)
        ->and($m['fries']->fresh()->category_id)->toBe($m['sides']->id);
});

it('C-15: a clash that only appears while saving is a 422 naming the row too', function (): void {
    $ctx = makeMerchantActor();
    $m = f2TwoMeals($ctx);
    // The plan sees no clash (the second meal is inactive); the meal turns
    // active while the row before it is written.
    DB::table('pos_meals')->where('name', 'Chicken meal')->update(['status' => 'inactive']);
    Product::creating(static function (Product $product): void {
        if ($product->name === 'Onion rings') {
            DB::table('pos_meals')->where('name', 'Chicken meal')->update(['status' => 'active']);
        }
    });

    $res = $this->post('/api/products/import/commit', ['file' => f2MenuXlsx([
        ['name', 'category', 'price'],
        ['Onion rings', 'Sides', '0.900'],
        ['Mushroom burger', 'Burgers', '2.200'],
    ])], ['Accept' => 'application/json']);

    $res->assertStatus(422)->assertJsonPath('reason', 'row_refused')->assertJsonPath('row', 3);
    expect((string) $res->json('message'))->toContain('Row 3')->toContain('"Mushroom burger" would be in both');
    expect(Product::query()->where('name', 'Onion rings')->exists())->toBeFalse();
});
