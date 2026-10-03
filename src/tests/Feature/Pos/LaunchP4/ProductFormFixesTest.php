<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B7 — product form fixes not covered elsewhere:
 *   L6: subcategories are hidden — the API refuses a new parent (it used to
 *       accept one although no page or device uses it); moving an existing
 *       subcategory back to the top level still works.
 *   M6: a shelf count below zero is flagged — the branch page lists cooked
 *       products' counts too, with below_zero; the catalogue list carries the
 *       per-branch counts it flags.
 * (H6/H7 are in BranchScopeTest, L5 in ChannelsTest.)
 */

use App\Enums\MerchantRole;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

it('L6 refuses a new parent category and keeps moving back to the top level possible', function (): void {
    $ctx = makeMerchantActor();
    $drinks = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);
    $smoothies = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Smoothies']);

    $this->postJson('/api/categories', ['name' => 'Hot drinks', 'parent_id' => $drinks->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.parent_id.0', 'Subcategories are not available yet. Keep the category at the top level.');
    $this->patchJson("/api/categories/{$smoothies->uuid}", ['parent_id' => $drinks->id])->assertStatus(422);
    expect(ProductCategory::query()->whereNotNull('parent_id')->count())->toBe(0);

    // An older subcategory can still be moved back to the top level.
    $old = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Old sub', 'parent_id' => $drinks->id]);
    $this->patchJson("/api/categories/{$old->uuid}", ['parent_id' => null])->assertOk();
    expect($old->fresh()->parent_id)->toBeNull();
});

it('L6 still answers a viewer with 403, before any validation', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $drinks = ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Drinks']);

    $this->postJson('/api/categories', ['name' => 'X', 'parent_id' => $drinks->id])->assertForbidden();
});

it('M6 shows cooked shelf counts on the branch page and flags any below zero', function (): void {
    $ctx = makeMerchantActor();
    $cake = p4Product($ctx['company'], 'Cake', '1.000', ['stock_mode' => 'cooked']);
    $water = p4Product($ctx['company'], 'Water', '0.200', ['stock_mode' => 'unit']);
    $juice = p4Product($ctx['company'], 'Juice', '0.500', ['stock_mode' => 'unit']);
    foreach ([[$cake, '-2.000'], [$water, '-1.000'], [$juice, '4.000']] as [$product, $qty]) {
        DB::table('pos_branch_product')->insert(['branch_id' => $ctx['branch']->id, 'product_id' => $product->id, 'is_available' => true, 'stock_qty' => $qty, 'created_at' => now(), 'updated_at' => now()]);
    }

    $rows = collect($this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/products")->assertOk()->json('data'))->keyBy('name');
    expect($rows['Cake'])->toMatchArray(['stock_mode' => 'cooked', 'stock_qty' => '-2.000', 'below_zero' => true])
        ->and($rows['Water']['below_zero'])->toBeTrue()
        ->and($rows['Juice']['below_zero'])->toBeFalse();

    // The catalogue list carries the per-branch counts the list flags.
    $cakeRow = collect($this->getJson('/api/products')->json('data'))->firstWhere('name', 'Cake');
    expect((float) $cakeRow['branches'][0]['stock_qty'])->toBe(-2.0);
});
