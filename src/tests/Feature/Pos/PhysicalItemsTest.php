<?php

declare(strict_types=1);

/**
 * PD3a — physical items: things that CANNOT be eaten, managed on the
 * Inventory page (never the catalogue).
 *
 *   GET/POST /api/physical-items + PATCH /api/physical-items/{uuid}
 *
 * The rows ride the product piece-counting machinery (unit + internal,
 * base_price forced 0) — covered here: the forced storage shape, the
 * kind (packaging vs general), the catalogue index exclusion, and that
 * the stock endpoints (incl. PD2 receive-cost→expense) accept them.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a physical item with the storage shape forced', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->postJson('/api/physical-items', [
        'name' => 'Cup 12oz',
        'name_ar' => 'كوب',
        'purpose' => 'packaging',
        'cost_price' => '0.045',
        'low_stock_threshold' => '100',
    ])->assertCreated();

    expect($res->json('data.purpose'))->toBe('packaging')
        ->and($res->json('data.central_quantity'))->toBe('0.000');

    $item = Product::query()->where('name', 'Cup 12oz')->firstOrFail();
    expect($item->is_internal)->toBeTrue()
        ->and($item->stock_mode)->toBe('unit')
        ->and((string) $item->base_price)->toBe('0.000')
        ->and($item->show_on_customer_tablet)->toBeFalse()
        ->and($item->internal_purpose)->toBe('packaging');

    // purpose is required — the kind decides food-attachability.
    $this->postJson('/api/physical-items', ['name' => 'Mystery'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['purpose']);
});

it('lists physical items only, with the central pool quantity', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Chips', 'stock_mode' => 'unit', 'is_internal' => false]);
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Bulb', 'stock_mode' => 'unit', 'is_internal' => true, 'internal_purpose' => 'general']);
    Product::factory()->for(Company::factory()->create(), 'company')->create(['name' => 'Foreign Cup', 'stock_mode' => 'unit', 'is_internal' => true]);

    $created = $this->postJson('/api/physical-items', ['name' => 'Cup', 'purpose' => 'packaging'])->json('data');
    $this->postJson("/api/products/{$created['uuid']}/stock/receive", ['quantity' => '40'])->assertOk();

    $rows = $this->getJson('/api/physical-items')->assertOk()->json('data');

    expect(collect($rows)->pluck('name')->all())->toBe(['Bulb', 'Cup'])
        ->and(collect($rows)->firstWhere('name', 'Cup')['central_quantity'])->toBe('40.000');
});

it('updates a physical item and 404s non-items + foreign tenants', function (): void {
    $ctx = makeMerchantActor();
    $created = $this->postJson('/api/physical-items', ['name' => 'Boxy', 'purpose' => 'packaging'])->json('data');

    $this->patchJson("/api/physical-items/{$created['uuid']}", [
        'name' => 'Meal Box',
        'purpose' => 'general',
        'status' => 'inactive',
    ])->assertOk();

    $item = Product::query()->where('uuid', $created['uuid'])->firstOrFail();
    expect($item->name)->toBe('Meal Box')
        ->and($item->internal_purpose)->toBe('general')
        ->and($item->status?->value ?? (string) $item->status)->toBe('inactive');

    // A sellable product is not reachable through this endpoint…
    $sellable = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'is_internal' => false]);
    $this->patchJson("/api/physical-items/{$sellable->uuid}", ['name' => 'X'])->assertNotFound();

    // …and neither is another tenant's item.
    $foreign = Product::factory()->for(Company::factory()->create(), 'company')->create(['stock_mode' => 'unit', 'is_internal' => true]);
    $this->patchJson("/api/physical-items/{$foreign->uuid}", ['name' => 'X'])->assertNotFound();
});

it('gates writes on inventory.manage', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    // Viewer holds inventory.view → the list is readable…
    $this->getJson('/api/physical-items')->assertOk();
    // …but never writable.
    $this->postJson('/api/physical-items', ['name' => 'Cup', 'purpose' => 'packaging'])->assertForbidden();
});

it('keeps physical items out of the catalogue products list', function (): void {
    $ctx = makeMerchantActor();
    Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte', 'stock_mode' => 'ingredient']);
    $this->postJson('/api/physical-items', ['name' => 'Cup', 'purpose' => 'packaging'])->assertCreated();

    $names = collect($this->getJson('/api/products')->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Latte']);
});

it('receives stock with a cost through the shared machinery (PD2 expense)', function (): void {
    $ctx = makeMerchantActor();
    $created = $this->postJson('/api/physical-items', ['name' => 'Cup 12oz', 'purpose' => 'packaging'])->json('data');

    $this->postJson("/api/products/{$created['uuid']}/stock/receive", [
        'quantity' => '500',
        'total_cost' => '22.500',
    ])->assertOk();

    $expense = Expense::query()->where('category', 'stock_purchases')->firstOrFail();
    expect((string) $expense->amount)->toBe('22.500')
        ->and($expense->note)->toContain('Cup 12oz');
});

it('deletes a physical item unless it is still attached to a product', function (): void {
    $ctx = makeMerchantActor();
    $coffee = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee', 'stock_mode' => 'ingredient']);
    $created = $this->postJson('/api/physical-items', ['name' => 'Cup', 'purpose' => 'packaging'])->json('data');

    $this->putJson("/api/products/{$coffee->uuid}/components", [
        'lines' => [['component_uuid' => $created['uuid'], 'quantity' => 1]],
    ])->assertOk();

    // Attached → refuse with a diagnosable message.
    $this->deleteJson("/api/physical-items/{$created['uuid']}")->assertStatus(422);

    // Detach, then delete succeeds (soft delete, history kept).
    $this->putJson("/api/products/{$coffee->uuid}/components", ['lines' => []])->assertOk();
    $this->deleteJson("/api/physical-items/{$created['uuid']}")->assertNoContent();
    expect(Product::withTrashed()->where('uuid', $created['uuid'])->firstOrFail()->trashed())->toBeTrue();

    // Sellable products are not deletable through this endpoint.
    $sellable = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'unit', 'is_internal' => false]);
    $this->deleteJson("/api/physical-items/{$sellable->uuid}")->assertNotFound();
});

it('refuses flipping a still-attached packaging item to branch use', function (): void {
    $ctx = makeMerchantActor();
    $coffee = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee', 'stock_mode' => 'ingredient']);
    $created = $this->postJson('/api/physical-items', ['name' => 'Cup', 'purpose' => 'packaging'])->json('data');
    $this->putJson("/api/products/{$coffee->uuid}/components", [
        'lines' => [['component_uuid' => $created['uuid'], 'quantity' => 1]],
    ])->assertOk();

    // "Branch use = never attached to food" must not become a lie while
    // sales keep consuming the attachment.
    $this->patchJson("/api/physical-items/{$created['uuid']}", ['purpose' => 'general'])->assertStatus(422);

    $this->putJson("/api/products/{$coffee->uuid}/components", ['lines' => []])->assertOk();
    $this->patchJson("/api/physical-items/{$created['uuid']}", ['purpose' => 'general'])->assertOk();
});

it('keeps internal rows out of category product counts and delete guards', function (): void {
    $ctx = makeMerchantActor();
    $category = \App\Models\ProductCategory::factory()->for($ctx['company'], 'company')->create(['name' => 'Legacy']);
    // A legacy physical item that still carries a category.
    Product::factory()->for($ctx['company'], 'company')->create([
        'stock_mode' => 'unit', 'is_internal' => true, 'category_id' => $category->id,
    ]);

    $row = collect($this->getJson('/api/categories')->assertOk()->json('data'))->firstWhere('name', 'Legacy');
    expect($row['products_count'])->toBe(0);

    // The invisible internal member must not block deletion either
    // (the FK is nullOnDelete — the orphan just loses its category).
    $this->deleteJson("/api/categories/{$category->uuid}")->assertNoContent();
});
