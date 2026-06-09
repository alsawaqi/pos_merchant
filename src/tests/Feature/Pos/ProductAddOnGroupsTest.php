<?php

declare(strict_types=1);

/**
 * Product-unique add-ons (v2 #6) — add-on groups privately OWNED by one product.
 *
 *   GET/POST /api/products/{uuid}/addon-groups  (catalogue.view / catalogue.manage)
 *
 * An owned group is never global, auto-attached to its product (so it ships in
 * that product's /device/config add-on ids), and hidden from the shared
 * Add-ons list. Options use the existing addon endpoints.
 */

use App\Enums\MerchantRole;
use App\Models\AddOnGroup;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('creates a product-owned add-on group, attached + never global', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte']);

    $res = $this->postJson("/api/products/{$product->uuid}/addon-groups", [
        'name' => 'Latte milk', 'selection_mode' => 'single',
    ])->assertCreated();

    expect($res->json('data.name'))->toBe('Latte milk');
    expect($res->json('data.owner_product_id'))->toBe($product->id);
    expect($res->json('data.is_global'))->toBeFalse();

    // Auto-attached to its product via the pivot (so it reaches the device).
    $group = AddOnGroup::query()->where('owner_product_id', $product->id)->firstOrFail();
    expect($group->products()->pluck('pos_products.id')->all())->toContain($product->id);
});

it('hides owned groups from the shared add-ons list', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    AddOnGroup::factory()->for($ctx['company'], 'company')->create(['name' => 'Shared sugar']);
    $this->postJson("/api/products/{$product->uuid}/addon-groups", ['name' => 'Private size'])->assertCreated();

    $shared = collect($this->getJson('/api/addon-groups')->json('data'))->pluck('name')->all();
    expect($shared)->toContain('Shared sugar');
    expect($shared)->not->toContain('Private size');
});

it('lists a product own add-on groups (with options)', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $created = $this->postJson("/api/products/{$product->uuid}/addon-groups", ['name' => 'Latte extras'])->json('data');
    // Add an option via the existing addons endpoint.
    $this->postJson("/api/addon-groups/{$created['uuid']}/addons", ['name' => 'Extra shot', 'price_delta' => '0.500'])->assertCreated();

    $rows = $this->getJson("/api/products/{$product->uuid}/addon-groups")->assertOk()->json('data');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('Latte extras');
    expect($rows[0]['addons'])->toHaveCount(1);
    expect($rows[0]['addons'][0]['name'])->toBe('Extra shot');
});

it('deletes an owned group despite its product attachment', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $created = $this->postJson("/api/products/{$product->uuid}/addon-groups", ['name' => 'Gone soon'])->json('data');

    // The shared "detach first" guard is skipped for product-owned groups.
    $this->deleteJson("/api/addon-groups/{$created['uuid']}")->assertNoContent();
    expect($this->getJson("/api/products/{$product->uuid}/addon-groups")->json('data'))->toHaveCount(0);
});

it('does not leak another tenant product (404)', function (): void {
    makeMerchantActor();
    $foreign = Product::factory()->for(Company::factory()->create(), 'company')->create();

    $this->getJson("/api/products/{$foreign->uuid}/addon-groups")->assertNotFound();
    $this->postJson("/api/products/{$foreign->uuid}/addon-groups", ['name' => 'X'])->assertNotFound();
});

it('gates create behind catalogue.manage', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $product = Product::factory()->for($ctx['company'], 'company')->create();

    $this->getJson("/api/products/{$product->uuid}/addon-groups")->assertOk();          // view allowed
    $this->postJson("/api/products/{$product->uuid}/addon-groups", ['name' => 'Nope'])->assertForbidden();
});
