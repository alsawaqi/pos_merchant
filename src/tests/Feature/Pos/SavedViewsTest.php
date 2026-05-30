<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Saved views — per-user filter presets for any portal screen.
 *
 * Personal bookmarks: no permission gate, scoped to (company_id, user_id).
 * Covers create/list/update/delete, the one-default-per-screen rule, per-user
 * name uniqueness, and isolation between users + companies.
 */
it('creates a saved view for the authenticated user', function (): void {
    $ctx = makeMerchantActor();

    $res = $this->postJson('/api/saved-views', [
        'view_key' => 'reports.sales',
        'name' => 'Last month, Branch A',
        'filters' => ['date_from' => '2026-05-01', 'date_to' => '2026-05-31', 'branch_id' => 7],
        'is_default' => true,
    ])->assertCreated();

    expect($res->json('data.view_key'))->toBe('reports.sales');
    expect($res->json('data.name'))->toBe('Last month, Branch A');
    expect($res->json('data.filters.branch_id'))->toBe(7);
    expect($res->json('data.is_default'))->toBeTrue();

    $this->assertDatabaseHas('pos_saved_views', [
        'company_id' => $ctx['company']->id,
        'user_id' => $ctx['user']->id,
        'view_key' => 'reports.sales',
        'name' => 'Last month, Branch A',
        'is_default' => true,
    ]);
});

it('lists only the caller own views and supports a view_key filter', function (): void {
    $ctx = makeMerchantActor();
    $mine = $ctx['user'];

    $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'A'])->assertCreated();
    $this->postJson('/api/saved-views', ['view_key' => 'customers', 'name' => 'B'])->assertCreated();

    // A different user (new company) creates one — must not leak into mine.
    makeMerchantActor();
    $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'Theirs'])->assertCreated();

    $this->actingAs($mine);

    $all = $this->getJson('/api/saved-views')->assertOk();
    expect(collect($all->json('data'))->pluck('name')->all())->toEqualCanonicalizing(['A', 'B']);

    $sales = $this->getJson('/api/saved-views?view_key=reports.sales')->assertOk();
    expect(collect($sales->json('data'))->pluck('name')->all())->toBe(['A']);
});

it('enforces one default per user per screen', function (): void {
    makeMerchantActor();

    $first = $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'First', 'is_default' => true])
        ->assertCreated()->json('data.uuid');
    $second = $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'Second', 'is_default' => true])
        ->assertCreated()->json('data.uuid');

    // Setting the second default cleared the first.
    $this->assertDatabaseHas('pos_saved_views', ['uuid' => $first, 'is_default' => false]);
    $this->assertDatabaseHas('pos_saved_views', ['uuid' => $second, 'is_default' => true]);

    // A default on ANOTHER screen is independent.
    $other = $this->postJson('/api/saved-views', ['view_key' => 'customers', 'name' => 'C', 'is_default' => true])
        ->assertCreated()->json('data.uuid');
    $this->assertDatabaseHas('pos_saved_views', ['uuid' => $second, 'is_default' => true]);
    $this->assertDatabaseHas('pos_saved_views', ['uuid' => $other, 'is_default' => true]);
});

it('refuses a duplicate name on the same screen for the same user', function (): void {
    makeMerchantActor();

    $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'Dupe'])->assertCreated();
    $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'Dupe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    // Same name on a DIFFERENT screen is fine.
    $this->postJson('/api/saved-views', ['view_key' => 'customers', 'name' => 'Dupe'])->assertCreated();
});

it('updates name, filters, and default flag', function (): void {
    makeMerchantActor();
    $uuid = $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'Old', 'filters' => ['a' => 1]])
        ->assertCreated()->json('data.uuid');

    $this->patchJson("/api/saved-views/{$uuid}", [
        'name' => 'New',
        'filters' => ['b' => 2],
        'is_default' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New')
        ->assertJsonPath('data.filters.b', 2)
        ->assertJsonPath('data.is_default', true);
});

it('deletes a saved view', function (): void {
    makeMerchantActor();
    $uuid = $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'Trash'])
        ->assertCreated()->json('data.uuid');

    $this->deleteJson("/api/saved-views/{$uuid}")->assertNoContent();
    $this->assertDatabaseMissing('pos_saved_views', ['uuid' => $uuid]);
});

it('404s when touching another user view', function (): void {
    $ctx = makeMerchantActor();
    $mine = $ctx['user'];
    $uuid = $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'Mine'])
        ->assertCreated()->json('data.uuid');

    // A different user can't read/update/delete it.
    makeMerchantActor();
    $this->patchJson("/api/saved-views/{$uuid}", ['name' => 'Hijack'])->assertNotFound();
    $this->deleteJson("/api/saved-views/{$uuid}")->assertNotFound();

    // ...and it survives untouched.
    $this->actingAs($mine);
    $this->assertDatabaseHas('pos_saved_views', ['uuid' => $uuid, 'name' => 'Mine']);
});

it('requires authentication', function (): void {
    $this->postJson('/api/saved-views', ['view_key' => 'reports.sales', 'name' => 'X'])
        ->assertUnauthorized();
});
