<?php

declare(strict_types=1);

/**
 * P-F9 — controller-level coverage for the offers API.
 *
 * Covers:
 *   - CRUD: create per type (canonical normalized config persists),
 *     update (diff-aware) + type change requires config, soft delete,
 *     cross-tenant 404
 *   - STRICT CONFIG VALIDATION: per-type shape failures land under the
 *     `config` key (empty selectors, qty bounds, free_count < qty,
 *     reward switches, bundle groups)
 *   - TENANT ISOLATION: foreign product/category ids inside config → 422
 *   - BUNDLE: auto_apply forced false on create AND on re-type
 *   - LIFECYCLE: pause + resume with status guards
 *   - PERMISSION MATRIX: Viewer view-only; Manager full (offers share
 *     the discounts.view / discounts.manage keys)
 *   - REPORT: sales report by_offer amounts + counts
 */

use App\Enums\MerchantRole;
use App\Enums\OfferStatus;
use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// =================== LIST + SHOW ===================

it('lists offers tenant-scoped', function (): void {
    $ctx = makeMerchantActor();
    Offer::factory()->for($ctx['company'], 'company')->create(['name' => 'Spend & Save']);

    $other = Company::factory()->create();
    Offer::factory()->for($other, 'company')->create();

    $response = $this->getJson('/api/offers')->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Spend & Save');
    expect($data[0]['type'])->toBe('spend_get');
    expect($data[0]['currently_active'])->toBeTrue();
});

it('returns 404 when showing an offer owned by another company', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Offer::factory()->for($other, 'company')->create();

    $this->getJson("/api/offers/{$foreign->uuid}")->assertNotFound();
});

// =================== CREATE — one per type ===================

it('creates a bogo offer with the canonical normalized config', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson('/api/offers', [
        'name' => 'Coffee BOGO',
        'name_ar' => 'اشترِ واحصل',
        'type' => 'bogo',
        'config' => [
            'buy' => ['product_ids' => [$p->id], 'category_ids' => [], 'qty' => 2],
            'get' => ['same_as_buy' => true, 'qty' => 1, 'percent_off' => 100],
        ],
        'dayofweek_mask' => 65,
        'time_start' => '14:00:00',
        'time_end' => '17:00:00',
        'max_per_order' => 2,
    ])->assertCreated();

    expect($response->json('data.type'))->toBe('bogo');
    expect($response->json('data.auto_apply'))->toBeTrue(); // default for non-bundle
    expect($response->json('data.max_per_order'))->toBe(2);
    // The normalized canonical shape — missing selector keys filled in.
    expect($response->json('data.config'))->toBe([
        'buy' => ['product_ids' => [$p->id], 'category_ids' => [], 'qty' => 2],
        'get' => ['same_as_buy' => true, 'product_ids' => [], 'category_ids' => [], 'qty' => 1, 'percent_off' => 100],
    ]);

    $row = Offer::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.offer.created',
        'auditable_id' => $row->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('creates a bundle offer and forces auto_apply false (cashier-picked)', function (): void {
    $ctx = makeMerchantActor();
    $p1 = Product::factory()->for($ctx['company'], 'company')->create();
    $p2 = Product::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson('/api/offers', [
        'name' => 'Meal Deal',
        'type' => 'bundle',
        'auto_apply' => true, // explicitly on — must be overridden
        'config' => [
            'price_baisas' => 2500,
            'groups' => [
                ['label' => 'Main', 'label_ar' => null, 'product_ids' => [$p1->id], 'qty' => 1],
                ['label' => 'Drink', 'label_ar' => 'مشروب', 'product_ids' => [$p1->id, $p2->id], 'qty' => 1],
            ],
        ],
    ])->assertCreated();

    expect($response->json('data.auto_apply'))->toBeFalse();
    expect($response->json('data.config.price_baisas'))->toBe(2500);
    expect($response->json('data.config.groups'))->toHaveCount(2);
    expect(Offer::query()->where('uuid', $response->json('data.uuid'))->firstOrFail()->auto_apply)->toBeFalse();
});

it('creates a multi_buy offer', function (): void {
    $ctx = makeMerchantActor();
    $cat = ProductCategory::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson('/api/offers', [
        'name' => '3 for 1 OMR',
        'type' => 'multi_buy',
        'config' => ['product_ids' => [], 'category_ids' => [$cat->id], 'qty' => 3, 'price_baisas' => 1000],
    ])->assertCreated();

    expect($response->json('data.config'))->toBe([
        'product_ids' => [],
        'category_ids' => [$cat->id],
        'qty' => 3,
        'price_baisas' => 1000,
    ]);
});

it('creates a cheapest_free offer', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson('/api/offers', [
        'name' => 'Buy 3, cheapest free',
        'type' => 'cheapest_free',
        'config' => ['product_ids' => [$p->id], 'qty' => 3, 'free_count' => 1],
    ])->assertCreated();

    expect($response->json('data.config'))->toBe([
        'product_ids' => [$p->id],
        'category_ids' => [],
        'qty' => 3,
        'free_count' => 1,
    ]);
});

it('creates the three spend_get reward variants', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $percent = $this->postJson('/api/offers', [
        'name' => 'Spend 5 get 10%',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 5000, 'reward_type' => 'percent_off', 'reward_value' => 10],
    ])->assertCreated();
    expect($percent->json('data.config.reward_value'))->toBe(10);

    $fixed = $this->postJson('/api/offers', [
        'name' => 'Spend 10 get 1 OMR off',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 10000, 'reward_type' => 'fixed_off', 'reward_value' => 1000],
    ])->assertCreated();
    expect($fixed->json('data.config.reward_value'))->toBe(1000);

    $free = $this->postJson('/api/offers', [
        'name' => 'Spend 8 get a free item',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 8000, 'reward_type' => 'free_product', 'reward_value' => null, 'reward_product_id' => $p->id],
    ])->assertCreated();
    expect($free->json('data.config'))->toBe([
        'min_subtotal_baisas' => 8000,
        'reward_type' => 'free_product',
        'reward_value' => null,
        'reward_product_id' => $p->id,
    ]);
});

// =================== STRICT CONFIG VALIDATION ===================

it('rejects a bogo with an empty buy selector', function (): void {
    makeMerchantActor();

    $this->postJson('/api/offers', [
        'name' => 'Bad BOGO',
        'type' => 'bogo',
        'config' => [
            'buy' => ['product_ids' => [], 'category_ids' => [], 'qty' => 1],
            'get' => ['same_as_buy' => true, 'qty' => 1, 'percent_off' => 100],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

it('rejects a bogo whose get selector is empty without same_as_buy', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/offers', [
        'name' => 'Bad BOGO get',
        'type' => 'bogo',
        'config' => [
            'buy' => ['product_ids' => [$p->id], 'qty' => 1],
            'get' => ['same_as_buy' => false, 'qty' => 1, 'percent_off' => 50],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

it('rejects a bogo percent_off outside 1..100', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/offers', [
        'name' => 'Bad percent',
        'type' => 'bogo',
        'config' => [
            'buy' => ['product_ids' => [$p->id], 'qty' => 1],
            'get' => ['same_as_buy' => true, 'qty' => 1, 'percent_off' => 150],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

it('rejects a bundle without groups, with an empty group, or with a zero price', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/offers', [
        'name' => 'No groups',
        'type' => 'bundle',
        'config' => ['price_baisas' => 2500, 'groups' => []],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    $this->postJson('/api/offers', [
        'name' => 'Empty group',
        'type' => 'bundle',
        'config' => ['price_baisas' => 2500, 'groups' => [['label' => 'Main', 'product_ids' => [], 'qty' => 1]]],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    $this->postJson('/api/offers', [
        'name' => 'Free bundle',
        'type' => 'bundle',
        'config' => ['price_baisas' => 0, 'groups' => [['label' => 'Main', 'product_ids' => [$p->id], 'qty' => 1]]],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

it('rejects a multi_buy with qty below 2 or an empty selector', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/offers', [
        'name' => 'One is not multi',
        'type' => 'multi_buy',
        'config' => ['product_ids' => [$p->id], 'qty' => 1, 'price_baisas' => 1000],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    $this->postJson('/api/offers', [
        'name' => 'Nothing selected',
        'type' => 'multi_buy',
        'config' => ['product_ids' => [], 'category_ids' => [], 'qty' => 3, 'price_baisas' => 1000],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

it('rejects a cheapest_free whose free_count is not below qty', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    $this->postJson('/api/offers', [
        'name' => 'All free',
        'type' => 'cheapest_free',
        'config' => ['product_ids' => [$p->id], 'qty' => 3, 'free_count' => 3],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

it('rejects spend_get reward shape violations', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();

    // free_product without a reward_product_id.
    $this->postJson('/api/offers', [
        'name' => 'Free what?',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 5000, 'reward_type' => 'free_product', 'reward_value' => null],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    // percent reward above 100.
    $this->postJson('/api/offers', [
        'name' => 'Too generous',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 5000, 'reward_type' => 'percent_off', 'reward_value' => 150],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    // reward_product_id leaking onto a non-free_product reward.
    $this->postJson('/api/offers', [
        'name' => 'Mixed signals',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 5000, 'reward_type' => 'percent_off', 'reward_value' => 10, 'reward_product_id' => $p->id],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    // unknown reward type.
    $this->postJson('/api/offers', [
        'name' => 'Mystery reward',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 5000, 'reward_type' => 'cashback', 'reward_value' => 10],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

// =================== TENANT ISOLATION OF CONFIG IDS ===================

it('rejects config product/category ids that belong to another company', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreignProduct = Product::factory()->for($other, 'company')->create();
    $foreignCategory = ProductCategory::factory()->for($other, 'company')->create();

    // bogo buy selector with a foreign product.
    $this->postJson('/api/offers', [
        'name' => 'Sneaky BOGO',
        'type' => 'bogo',
        'config' => [
            'buy' => ['product_ids' => [$foreignProduct->id], 'qty' => 1],
            'get' => ['same_as_buy' => true, 'qty' => 1, 'percent_off' => 100],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    // multi_buy category selector with a foreign category.
    $this->postJson('/api/offers', [
        'name' => 'Sneaky multi',
        'type' => 'multi_buy',
        'config' => ['category_ids' => [$foreignCategory->id], 'qty' => 3, 'price_baisas' => 1000],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    // bundle group with a foreign product.
    $this->postJson('/api/offers', [
        'name' => 'Sneaky bundle',
        'type' => 'bundle',
        'config' => ['price_baisas' => 2500, 'groups' => [['label' => 'Main', 'product_ids' => [$foreignProduct->id], 'qty' => 1]]],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);

    // spend_get free product from another company.
    $this->postJson('/api/offers', [
        'name' => 'Sneaky freebie',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 5000, 'reward_type' => 'free_product', 'reward_value' => null, 'reward_product_id' => $foreignProduct->id],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

// =================== UPDATE ===================

it('updates an offer with diff-aware audit', function (): void {
    $ctx = makeMerchantActor();
    $offer = Offer::factory()->for($ctx['company'], 'company')->create(['name' => 'Old']);

    $this->patchJson("/api/offers/{$offer->uuid}", [
        'name' => 'New',
        'max_per_order' => 3,
    ])->assertOk();

    $offer->refresh();
    expect($offer->name)->toBe('New');
    expect($offer->max_per_order)->toBe(3);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.offer.updated',
        'auditable_id' => $offer->id,
    ]);
});

it('writes no audit row on a no-op update', function (): void {
    $ctx = makeMerchantActor();
    $offer = Offer::factory()->for($ctx['company'], 'company')->create(['name' => 'Same']);

    $this->patchJson("/api/offers/{$offer->uuid}", ['name' => 'Same'])->assertOk();

    $audits = DB::table('pos_audit_logs')
        ->where('event', 'catalogue.offer.updated')
        ->where('auditable_id', $offer->id)
        ->count();
    expect($audits)->toBe(0);
});

it('requires a config when changing the type', function (): void {
    $ctx = makeMerchantActor();
    $offer = Offer::factory()->for($ctx['company'], 'company')->create(); // spend_get

    // Type flip without a config → 422 (the stored spend_get config
    // can't silently masquerade as a multi_buy one).
    $this->patchJson("/api/offers/{$offer->uuid}", ['type' => 'multi_buy'])
        ->assertStatus(422)->assertJsonValidationErrors(['config']);

    // With a valid config for the new type it succeeds.
    $p = Product::factory()->for($ctx['company'], 'company')->create();
    $this->patchJson("/api/offers/{$offer->uuid}", [
        'type' => 'multi_buy',
        'config' => ['product_ids' => [$p->id], 'qty' => 3, 'price_baisas' => 900],
    ])->assertOk();

    $offer->refresh();
    expect($offer->type->value)->toBe('multi_buy');
    expect($offer->config['price_baisas'])->toBe(900);
});

it('validates a supplied config against the CURRENT type when type is omitted', function (): void {
    $ctx = makeMerchantActor();
    $offer = Offer::factory()->for($ctx['company'], 'company')->create(); // spend_get

    // A multi_buy-shaped config is invalid for the stored spend_get type.
    $this->patchJson("/api/offers/{$offer->uuid}", [
        'config' => ['product_ids' => [1], 'qty' => 3, 'price_baisas' => 900],
    ])->assertStatus(422)->assertJsonValidationErrors(['config']);
});

it('forces auto_apply false when re-typing an offer to bundle', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create();
    $offer = Offer::factory()->for($ctx['company'], 'company')->create(['auto_apply' => true]);

    $this->patchJson("/api/offers/{$offer->uuid}", [
        'type' => 'bundle',
        'auto_apply' => true, // must be overridden
        'config' => ['price_baisas' => 1500, 'groups' => [['label' => 'Pick one', 'product_ids' => [$p->id], 'qty' => 1]]],
    ])->assertOk();

    expect($offer->refresh()->auto_apply)->toBeFalse();
});

it('returns 404 when updating a foreign-tenant offer', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $foreign = Offer::factory()->for($other, 'company')->create();

    $this->patchJson("/api/offers/{$foreign->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

// =================== DELETE ===================

it('soft-deletes an offer + writes audit', function (): void {
    $ctx = makeMerchantActor();
    $offer = Offer::factory()->for($ctx['company'], 'company')->create();
    $id = $offer->id;

    $this->deleteJson("/api/offers/{$offer->uuid}")->assertNoContent();

    expect(Offer::query()->find($id))->toBeNull();
    expect(Offer::withTrashed()->find($id))->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.offer.deleted',
        'auditable_id' => $id,
    ]);
});

// =================== PAUSE + RESUME (status toggle) ===================

it('pauses an active offer; refuses to pause an already-paused one', function (): void {
    $ctx = makeMerchantActor();
    $offer = Offer::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/offers/{$offer->uuid}/pause")->assertOk()
        ->assertJsonPath('data.status', 'paused');

    $response = $this->postJson("/api/offers/{$offer->uuid}/pause")->assertStatus(422);
    expect($response->json('message'))->toContain('active');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'catalogue.offer.paused',
        'auditable_id' => $offer->id,
    ]);
});

it('resumes a paused offer; refuses to resume an active one', function (): void {
    $ctx = makeMerchantActor();
    $offer = Offer::factory()->for($ctx['company'], 'company')->paused()->create();

    $this->postJson("/api/offers/{$offer->uuid}/resume")->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($offer->refresh()->status)->toBe(OfferStatus::Active);

    $response = $this->postJson("/api/offers/{$offer->uuid}/resume")->assertStatus(422);
    expect($response->json('message'))->toContain('paused');
});

// =================== PERMISSION MATRIX ===================

it('lets a Viewer GET but forbids every offer write', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $offer = Offer::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/offers')->assertOk();
    $this->getJson("/api/offers/{$offer->uuid}")->assertOk();

    $this->postJson('/api/offers', [
        'name' => 'X',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 5000, 'reward_type' => 'percent_off', 'reward_value' => 10],
    ])->assertForbidden();
    $this->patchJson("/api/offers/{$offer->uuid}", ['name' => 'X'])->assertForbidden();
    $this->deleteJson("/api/offers/{$offer->uuid}")->assertForbidden();
    $this->postJson("/api/offers/{$offer->uuid}/pause")->assertForbidden();
    $this->postJson("/api/offers/{$offer->uuid}/resume")->assertForbidden();
});

it('lets a Manager run the full offer lifecycle', function (): void {
    makeMerchantActor(MerchantRole::Manager->value);

    $created = $this->postJson('/api/offers', [
        'name' => 'Mgr Offer',
        'type' => 'spend_get',
        'config' => ['min_subtotal_baisas' => 5000, 'reward_type' => 'percent_off', 'reward_value' => 15],
    ])->assertCreated();
    $uuid = $created->json('data.uuid');

    $this->postJson("/api/offers/{$uuid}/pause")->assertOk();
    $this->postJson("/api/offers/{$uuid}/resume")->assertOk();
    $this->deleteJson("/api/offers/{$uuid}")->assertNoContent();
});

// =================== SALES REPORT — by_offer ===================

it('reports by_offer amounts and counts from offer-tagged discount rows', function (): void {
    $ctx = makeMerchantActor();
    $offerA = Offer::factory()->for($ctx['company'], 'company')->create(['name' => 'Meal Deal']);
    $offerB = Offer::factory()->for($ctx['company'], 'company')->create(['name' => 'Coffee BOGO']);

    $o1 = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '10.000', 'discount_total' => '3.000', 'tax_total' => '0.000',
        'grand_total' => '7.000', 'opened_at' => '2026-06-10 12:00:00',
    ]);
    $o2 = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '8.000', 'discount_total' => '1.500', 'tax_total' => '0.000',
        'grand_total' => '6.500', 'opened_at' => '2026-06-12 18:00:00',
    ]);
    // An order OUTSIDE the window — its application must not count.
    $o3 = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '9.000', 'discount_total' => '2.000', 'tax_total' => '0.000',
        'grand_total' => '7.000', 'opened_at' => '2026-07-05 12:00:00',
    ]);

    $t = ['created_at' => now(), 'updated_at' => now()];
    DB::table('pos_order_discounts')->insert([
        // Meal Deal applied twice on order 1 (max_per_order style) + once on order 2.
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o1->id, 'offer_id' => $offerA->id, 'name_snapshot' => 'Meal Deal', 'amount' => '1.500', 'applied_at' => $o1->opened_at] + $t,
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o1->id, 'offer_id' => $offerA->id, 'name_snapshot' => 'Meal Deal', 'amount' => '1.500', 'applied_at' => $o1->opened_at] + $t,
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o2->id, 'offer_id' => $offerA->id, 'name_snapshot' => 'Meal Deal', 'amount' => '1.000', 'applied_at' => $o2->opened_at] + $t,
        // Coffee BOGO once on order 2.
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o2->id, 'offer_id' => $offerB->id, 'name_snapshot' => 'Coffee BOGO', 'amount' => '0.500', 'applied_at' => $o2->opened_at] + $t,
        // A PLAIN discount row (no offer) on order 1 — excluded from by_offer.
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o1->id, 'offer_id' => null, 'name_snapshot' => 'Manual', 'amount' => '0.250', 'applied_at' => $o1->opened_at] + $t,
        // Offer application on the out-of-window order — excluded.
        ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $o3->id, 'offer_id' => $offerB->id, 'name_snapshot' => 'Coffee BOGO', 'amount' => '2.000', 'applied_at' => $o3->opened_at] + $t,
    ]);

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    $byOffer = $response->json('data.by_offer');

    expect($byOffer)->toHaveCount(2);
    // Ordered by amount DESC: Meal Deal (4.000 over 3 applications) first.
    expect($byOffer[0]['offer_id'])->toBe($offerA->id);
    expect($byOffer[0]['name'])->toBe('Meal Deal');
    expect($byOffer[0]['amount'])->toBe('4.000');
    expect($byOffer[0]['count'])->toBe(3);
    expect($byOffer[1]['offer_id'])->toBe($offerB->id);
    expect($byOffer[1]['amount'])->toBe('0.500');
    expect($byOffer[1]['count'])->toBe(1);
});

it('returns an empty by_offer list when no offer applications exist', function (): void {
    makeMerchantActor();

    $response = $this->getJson('/api/reports/sales?date_from=2026-06-01&date_to=2026-06-30')->assertOk();
    expect($response->json('data.by_offer'))->toBe([]);
});
