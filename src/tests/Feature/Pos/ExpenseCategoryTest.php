<?php

declare(strict_types=1);

/**
 * Custom expense categories (v2 #7) — company-managed CRUD + the
 * per-company validation that replaced the fixed ExpenseCategory enum.
 *
 *   GET/POST /api/expense-categories, PATCH/DELETE .../{uuid}
 *   POST /api/expenses now validates category against the company's keys.
 */

use App\Models\Company;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('auto-seeds the seven default categories on first index', function (): void {
    makeMerchantActor();

    $res = $this->getJson('/api/expense-categories')->assertOk();

    // PD2 added stock_purchases (bought-in goods purchases).
    expect($res->json('data'))->toHaveCount(7);
    $keys = collect($res->json('data'))->pluck('key')->all();
    expect($keys)->toContain('utilities', 'supplies', 'ingredients', 'maintenance', 'salaries', 'other', 'stock_purchases');
});

it('creates a custom category with a slug key', function (): void {
    makeMerchantActor();

    $res = $this->postJson('/api/expense-categories', ['name' => 'Marketing & Ads'])->assertCreated();

    expect($res->json('data.name'))->toBe('Marketing & Ads');
    expect($res->json('data.key'))->toBe('marketing-ads');
    expect($res->json('data.is_active'))->toBeTrue();
});

it('rejects a duplicate category name', function (): void {
    makeMerchantActor();

    $this->postJson('/api/expense-categories', ['name' => 'Marketing'])->assertCreated();
    $this->postJson('/api/expense-categories', ['name' => 'Marketing'])->assertStatus(422);
});

it('updates name + is_active + sort_order but never the key', function (): void {
    makeMerchantActor();
    $created = $this->postJson('/api/expense-categories', ['name' => 'Marketing'])->json('data');

    $res = $this->patchJson("/api/expense-categories/{$created['uuid']}", [
        'name' => 'Promotions', 'is_active' => false, 'sort_order' => 9,
    ])->assertOk();

    expect($res->json('data.name'))->toBe('Promotions');
    expect($res->json('data.key'))->toBe('marketing');     // key is stable
    expect($res->json('data.is_active'))->toBeFalse();
    expect($res->json('data.sort_order'))->toBe(9);
});

it('soft-deletes a category (drops from the list)', function (): void {
    makeMerchantActor();
    $created = $this->postJson('/api/expense-categories', ['name' => 'Marketing'])->json('data');

    $this->deleteJson("/api/expense-categories/{$created['uuid']}")->assertNoContent();

    $keys = collect($this->getJson('/api/expense-categories')->json('data'))->pluck('key')->all();
    expect($keys)->not->toContain('marketing');
});

it('does not leak another tenant category (404)', function (): void {
    makeMerchantActor();
    $foreignCompany = Company::factory()->create();
    $foreign = ExpenseCategory::create([
        'company_id' => $foreignCompany->id, 'name' => 'Secret', 'key' => 'secret', 'is_active' => true, 'sort_order' => 0,
    ]);

    $this->patchJson("/api/expense-categories/{$foreign->uuid}", ['name' => 'Y'])->assertNotFound();
    $this->deleteJson("/api/expense-categories/{$foreign->uuid}")->assertNotFound();
});

it('gates endpoints behind expenses permissions', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->getJson('/api/expense-categories')->assertForbidden();
    $this->postJson('/api/expense-categories', ['name' => 'X'])->assertForbidden();
});

it('logs an expense with a valid (default-seeded) category key', function (): void {
    makeMerchantActor();

    // LogExpenseAction seeds defaults, so 'utilities' is valid without pre-seeding.
    $res = $this->postJson('/api/expenses', ['category' => 'utilities', 'amount' => '12.500'])->assertCreated();

    expect($res->json('data.category'))->toBe('utilities');
});

it('rejects an expense with an unknown category key', function (): void {
    makeMerchantActor();

    $this->postJson('/api/expenses', ['category' => 'not-a-real-category', 'amount' => '5.000'])->assertStatus(422);
});

it('logs an expense against a newly created custom category', function (): void {
    makeMerchantActor();
    $this->postJson('/api/expense-categories', ['name' => 'Marketing'])->assertCreated();

    $res = $this->postJson('/api/expenses', ['category' => 'marketing', 'amount' => '7.000'])->assertCreated();

    expect($res->json('data.category'))->toBe('marketing');
});
