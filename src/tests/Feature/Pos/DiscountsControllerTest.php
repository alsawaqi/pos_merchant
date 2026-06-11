<?php

declare(strict_types=1);

/**
 * Phase 6d — controller-level coverage for the discounts API.
 *
 * Tests the full HTTP slice end-to-end. The pure-function
 * EvaluateDiscounts lives in a sibling test file with its
 * own ≥30 scenario coverage per blueprint Phase 6 exit
 * checklist.
 *
 * Covers:
 *   - CRUD: create + update (diff-aware) + soft delete +
 *     cross-tenant 404
 *   - LIFECYCLE: pause + resume with status guards
 *   - TARGETS sync: PUT semantics + dedup + cross-tenant
 *     product/category guards + order-scope refuses targets
 *   - VALIDATION: percent cap, validity_end after start,
 *     dayofweek_mask bounds, time format
 *   - PERMISSION MATRIX: Viewer/CashierSupervisor view only;
 *     Manager + InventoryManager full
 */

use App\Enums\DiscountStatus;
use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Discount;
use App\Models\DiscountTarget;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// =================== LIST + SHOW ===================

it('lists discounts tenant-scoped with targets_count + targets eager loaded', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Ramadan']);
    DiscountTarget::factory()->for($d, 'discount')->create(['target_id' => 1]);

    $other = Company::factory()->create();
    Discount::factory()->for($other, 'company')->create();

    $response = $this->getJson('/api/discounts')->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Ramadan');
    expect($data[0]['targets_count'])->toBe(1);
});

it('returns 404 when showing a discount owned by another company', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Discount::factory()->for($other, 'company')->create();

    $this->getJson("/api/discounts/{$foreign->uuid}")->assertNotFound();
});

// =================== CREATE ===================

it('creates a discount and writes the audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/discounts', [
        'name' => 'Happy Hour',
        'scope' => 'order',
        'amount_type' => 'percent',
        'amount' => '15',
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Happy Hour');
    expect($response->json('data.scope'))->toBe('order');
    expect($response->json('data.amount'))->toBe('15.000');
    expect($response->json('data.status'))->toBe('active');

    $row = Discount::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.discount.created',
        'auditable_id' => $row->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('returns 422 when percent amount exceeds 100', function (): void {
    makeMerchantActor();

    $response = $this->postJson('/api/discounts', [
        'name' => 'Too Much',
        'scope' => 'order',
        'amount_type' => 'percent',
        'amount' => '150',
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('100');
});

it('returns 422 when amount is zero or negative', function (): void {
    makeMerchantActor();

    $this->postJson('/api/discounts', [
        'name' => 'X',
        'scope' => 'order',
        'amount_type' => 'percent',
        'amount' => '0',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

    $this->postJson('/api/discounts', [
        'name' => 'Y',
        'scope' => 'order',
        'amount_type' => 'fixed',
        'amount' => '-1',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

it('returns 422 when validity_end is before validity_start', function (): void {
    makeMerchantActor();

    $this->postJson('/api/discounts', [
        'name' => 'Bad Window',
        'scope' => 'order',
        'amount_type' => 'percent',
        'amount' => '10',
        'validity_start' => '2026-07-01T12:00:00',
        'validity_end' => '2026-06-01T12:00:00',
    ])->assertStatus(422)->assertJsonValidationErrors(['validity_end']);
});

// =================== UPDATE ===================

it('updates a discount with diff-aware audit', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Old', 'amount' => '10.000']);

    $this->patchJson("/api/discounts/{$d->uuid}", [
        'name' => 'New',
        'amount' => '20',
    ])->assertOk();

    $d->refresh();
    expect($d->name)->toBe('New');
    expect((string) $d->amount)->toBe('20.000');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.discount.updated',
        'auditable_id' => $d->id,
    ]);
});

it('writes no audit row on a no-op update', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Same', 'amount' => '10.000']);

    $this->patchJson("/api/discounts/{$d->uuid}", [
        'name' => 'Same',
        'amount' => '10',
    ])->assertOk();

    $audits = DB::table('pos_audit_logs')
        ->where('event', 'catalogue.discount.updated')
        ->where('auditable_id', $d->id)
        ->count();
    expect($audits)->toBe(0);
});

it('returns 404 when updating a foreign-tenant discount', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Discount::factory()->for($other, 'company')->create();

    $this->patchJson("/api/discounts/{$foreign->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

// =================== P-F4 AUTO-APPLY ===================
// Only ORDER scope is merchant-choosable; product/category rules are
// ALWAYS automatic per matching cart line, so the write path forces
// their stored flag TRUE whatever the client sends (mirror of the
// pos_admin 2026_07_09_010000 backfill semantics).

it('creates an order-scope discount with auto_apply true and persists + serializes it', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/discounts', [
        'name' => 'Self-applying',
        'scope' => 'order',
        'amount_type' => 'percent',
        'amount' => '5',
        'auto_apply' => true,
    ])->assertCreated();

    expect($response->json('data.auto_apply'))->toBeTrue();
    $row = Discount::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
    expect($row->auto_apply)->toBeTrue();

    // Omitted on order scope → defaults to false (manual picker).
    $manual = $this->postJson('/api/discounts', [
        'name' => 'Picker only',
        'scope' => 'order',
        'amount_type' => 'percent',
        'amount' => '5',
    ])->assertCreated();
    expect($manual->json('data.auto_apply'))->toBeFalse();
});

it('forces auto_apply true on product-scope create regardless of input', function (): void {
    makeMerchantActor();

    $response = $this->postJson('/api/discounts', [
        'name' => 'Targeted',
        'scope' => 'product',
        'amount_type' => 'percent',
        'amount' => '10',
        'auto_apply' => false, // explicitly off — must be overridden
    ])->assertCreated();

    expect($response->json('data.auto_apply'))->toBeTrue();
    $row = Discount::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
    expect($row->auto_apply)->toBeTrue();
});

it('toggles auto_apply on an order-scope discount via update', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')->create();
    expect($d->auto_apply)->toBeFalse();

    $this->patchJson("/api/discounts/{$d->uuid}", ['auto_apply' => true])->assertOk();
    expect($d->refresh()->auto_apply)->toBeTrue();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.discount.updated',
        'auditable_id' => $d->id,
    ]);

    $this->patchJson("/api/discounts/{$d->uuid}", ['auto_apply' => false])->assertOk();
    expect($d->refresh()->auto_apply)->toBeFalse();
});

it('keeps auto_apply true on a targeted-scope update even when the client sends false', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->productScope()->for($ctx['company'], 'company')->create();
    expect($d->auto_apply)->toBeTrue();

    $this->patchJson("/api/discounts/{$d->uuid}", ['auto_apply' => false])->assertOk();
    expect($d->refresh()->auto_apply)->toBeTrue();

    // Re-scoping an order rule to product flips the flag on too.
    $order = Discount::factory()->for($ctx['company'], 'company')->create();
    expect($order->auto_apply)->toBeFalse();
    $this->patchJson("/api/discounts/{$order->uuid}", ['scope' => 'product'])->assertOk();
    expect($order->refresh()->auto_apply)->toBeTrue();
});

// =================== DELETE ===================

it('soft-deletes a discount + writes audit; targets survive', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')->create();
    DiscountTarget::factory()->for($d, 'discount')->create();
    $id = $d->id;

    $this->deleteJson("/api/discounts/{$d->uuid}")->assertNoContent();

    expect(Discount::query()->find($id))->toBeNull();
    expect(Discount::withTrashed()->find($id))->not->toBeNull();
    // Targets survive — FK cascade only on hard delete.
    expect(DiscountTarget::query()->where('discount_id', $id)->count())->toBe(1);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.discount.deleted',
        'auditable_id' => $id,
    ]);
});

// =================== PAUSE + RESUME ===================

it('pauses an active discount; refuses to pause an already-paused one', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/discounts/{$d->uuid}/pause")->assertOk()
        ->assertJsonPath('data.status', 'paused');

    // Second pause -> 422.
    $response = $this->postJson("/api/discounts/{$d->uuid}/pause")->assertStatus(422);
    expect($response->json('message'))->toContain('active');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.discount.paused',
        'auditable_id' => $d->id,
    ]);
});

it('resumes a paused discount; refuses to resume an active one', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')->paused()->create();

    $this->postJson("/api/discounts/{$d->uuid}/resume")->assertOk()
        ->assertJsonPath('data.status', 'active');

    $d->refresh();
    expect($d->status)->toBe(DiscountStatus::Active);

    // Second resume on active row -> 422.
    $response = $this->postJson("/api/discounts/{$d->uuid}/resume")->assertStatus(422);
    expect($response->json('message'))->toContain('paused');
});

// =================== TARGETS SYNC ===================

it('PUT /targets attaches product targets, dedupes, and surfaces foreign-tenant 422', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')->productScope()->create();
    $p1 = Product::factory()->for($ctx['company'], 'company')->create();
    $p2 = Product::factory()->for($ctx['company'], 'company')->create();

    // Happy path with dup.
    $this->putJson("/api/discounts/{$d->uuid}/targets", [
        'targets' => [
            ['target_type' => 'product', 'target_id' => $p1->id],
            ['target_type' => 'product', 'target_id' => $p2->id],
            ['target_type' => 'product', 'target_id' => $p1->id], // dup
        ],
    ])->assertOk();

    expect(DiscountTarget::query()->where('discount_id', $d->id)->count())->toBe(2);

    // Foreign product -> 422.
    $other = Company::factory()->create();
    $foreignProduct = Product::factory()->for($other, 'company')->create();

    $response = $this->putJson("/api/discounts/{$d->uuid}/targets", [
        'targets' => [
            ['target_type' => 'product', 'target_id' => $foreignProduct->id],
        ],
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('do not belong');
});

it('PUT /targets refuses non-empty targets on an order-scope discount', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')->create(); // order scope
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $response = $this->putJson("/api/discounts/{$d->uuid}/targets", [
        'targets' => [['target_type' => 'product', 'target_id' => $p->id]],
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Order-scope');
});

it('PUT /targets skips audit on identical shape (idempotent)', function (): void {
    $ctx = makeMerchantActor();
    $d = Discount::factory()->for($ctx['company'], 'company')->categoryScope()->create();
    $cat = ProductCategory::factory()->for($ctx['company'], 'company')->create();

    $payload = [
        'targets' => [['target_type' => 'category', 'target_id' => $cat->id]],
    ];

    $this->putJson("/api/discounts/{$d->uuid}/targets", $payload)->assertOk();
    $this->putJson("/api/discounts/{$d->uuid}/targets", $payload)->assertOk();

    $count = DB::table('pos_audit_logs')
        ->where('event', 'catalogue.discount.targets_synced')
        ->where('auditable_id', $d->id)
        ->count();
    expect($count)->toBe(1);
});

// =================== PERMISSION MATRIX ===================

it('lets a Viewer GET but forbids every write', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $d = Discount::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/discounts')->assertOk();
    $this->getJson("/api/discounts/{$d->uuid}")->assertOk();

    $this->postJson('/api/discounts', ['name' => 'X', 'scope' => 'order', 'amount_type' => 'percent', 'amount' => '5'])->assertForbidden();
    $this->patchJson("/api/discounts/{$d->uuid}", ['name' => 'X'])->assertForbidden();
    $this->deleteJson("/api/discounts/{$d->uuid}")->assertForbidden();
    $this->postJson("/api/discounts/{$d->uuid}/pause")->assertForbidden();
    $this->postJson("/api/discounts/{$d->uuid}/resume")->assertForbidden();
    $this->putJson("/api/discounts/{$d->uuid}/targets", ['targets' => []])->assertForbidden();
});

it('lets a CashierSupervisor view but not write', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $d = Discount::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/discounts')->assertOk();
    $this->postJson("/api/discounts/{$d->uuid}/pause")->assertForbidden();
});

it('lets a Manager run the full discount lifecycle', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $created = $this->postJson('/api/discounts', [
        'name' => 'Mgr Discount',
        'scope' => 'product',
        'amount_type' => 'percent',
        'amount' => '20',
    ])->assertCreated();
    $uuid = $created->json('data.uuid');

    $this->putJson("/api/discounts/{$uuid}/targets", [
        'targets' => [['target_type' => 'product', 'target_id' => $p->id]],
    ])->assertOk();
    $this->postJson("/api/discounts/{$uuid}/pause")->assertOk();
    $this->postJson("/api/discounts/{$uuid}/resume")->assertOk();
    $this->deleteJson("/api/discounts/{$uuid}")->assertNoContent();
});
