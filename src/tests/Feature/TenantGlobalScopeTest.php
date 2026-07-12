<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\Scopes\BelongsToCompanyScope;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Locks in the Phase 2a tenant global scope (BelongsToCompanyScope) using
 * Product as the representative company-owned model. The behaviour proven here
 * is what the trait guarantees for all 45 company-owned models.
 */
it('restricts Eloquent reads to the pinned tenant company', function (): void {
    $actor = makeMerchantActor();
    $companyA = $actor['company'];
    $companyB = Company::factory()->create();

    Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    // Context is pinned to company A by makeMerchantActor().
    expect(Product::count())->toBe(1);
    expect(Product::pluck('company_id')->unique()->values()->all())->toEqual([$companyA->id]);

    // The whole point: a controller/action that FORGOT its ownership guard
    // still cannot read another tenant's row through Eloquent.
    expect(Product::find($productB->getKey()))->toBeNull();
    expect(Product::where('id', $productB->getKey())->first())->toBeNull();
});

it('is a no-op when no tenant is pinned (guests / auth flows / console)', function (): void {
    $actor = makeMerchantActor();
    $companyB = Company::factory()->create();
    Product::factory()->create(['company_id' => $actor['company']->id]);
    Product::factory()->create(['company_id' => $companyB->id]);

    app(MerchantTenantContext::class)->set(null);

    expect(Product::count())->toBe(2);
});

it('can be bypassed with withoutGlobalScope for cross-tenant tooling', function (): void {
    $actor = makeMerchantActor();
    $companyB = Company::factory()->create();
    Product::factory()->create(['company_id' => $actor['company']->id]);
    Product::factory()->create(['company_id' => $companyB->id]);

    expect(Product::withoutGlobalScope(BelongsToCompanyScope::class)->count())->toBe(2);
});

it('stamps company_id from the pinned tenant on create when left empty', function (): void {
    $actor = makeMerchantActor();

    $product = Product::factory()->create(['company_id' => null]);

    expect($product->company_id)->toBe($actor['company']->id)
        ->and($product->fresh()->company_id)->toBe($actor['company']->id);
});
