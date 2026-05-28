<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 5c WasteController + RecordWasteAction.
 *
 * The waste flow is the canonical example of a "two-writes-one-
 * transaction" inventory event:
 *   - WasteRecord (queryable row, quantity POSITIVE)
 *   - StockMovement (signed-NEGATIVE counterpart, type=waste)
 *   - branch_stock decremented to match
 * Plus TWO audit rows: inventory.waste.recorded (from
 * RecordWasteAction) AND inventory.movement.created (from the
 * WriteStockMovementAction call inside it).
 *
 * Covers:
 *   - HAPPY PATH: all of the above land atomically + the response
 *     payload exposes total_cost + the loaded ingredient/branch.
 *   - VALIDATION: reason=other requires notes, qty must be > 0,
 *     qty cannot exceed current branch_stock balance, unknown
 *     reason is rejected.
 *   - CROSS-TENANT: foreign ingredient uuid → 422, foreign
 *     branch uuid → 404.
 *   - INDEX: paginated, scoped to the route branch, filters by
 *     reason + by ingredient uuid (with cross-tenant uuid silently
 *     returning zero, no info leak).
 *   - PERMISSION GATES: InventoryView for list, InventoryManage
 *     for record. Viewer can list but not record. CashierSupervisor
 *     can't record. InventoryManager can.
 */

use App\Enums\MerchantRole;
use App\Enums\StockMovementType;
use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== HAPPY PATH ===================

it('records a waste event and creates the row + signed-negative movement + decrements branch_stock + writes 2 audit rows', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Whole Milk', 'default_unit_cost' => '1.500']);

    // Seed a non-zero balance so the waste has something to
    // consume. Without this the sufficient-stock guard inside
    // the Action would refuse the request.
    BranchStock::factory()
        ->for($ctx['branch'], 'branch')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '5.000']);

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '1.000',
        'reason' => WasteReason::Spoiled->value,
        'notes' => 'Past sell-by date',
    ])->assertCreated();

    // ----- DB invariants -----

    // WasteRecord row written with POSITIVE quantity (the
    // signed-negative version lives on the stock_movement).
    $waste = WasteRecord::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail();
    expect((string) $waste->quantity)->toBe('1.000');
    expect($waste->reason)->toBe(WasteReason::Spoiled);
    expect($waste->notes)->toBe('Past sell-by date');
    // Cost frozen at record time — future ingredient cost edits
    // mustn't shift this row's reported total_cost.
    expect((string) $waste->unit_cost_at_time)->toBe('1.500');

    // Matching stock_movement: type=waste, signed-NEGATIVE,
    // back-references this WasteRecord.
    $movement = StockMovement::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->where('movement_type', StockMovementType::Waste->value)
        ->firstOrFail();
    expect((string) $movement->quantity)->toBe('-1.000');
    expect($movement->reference_type)->toBe(WasteRecord::class);
    expect((int) $movement->reference_id)->toBe($waste->id);

    // Balance row decremented: 5.000 → 4.000.
    $balance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ingredient->id)
        ->firstOrFail();
    expect((string) $balance->quantity)->toBe('4.000');

    // TWO audit rows: the waste-specific one + the movement-
    // created one from WriteStockMovementAction.
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.waste.recorded',
        'auditable_id' => $waste->id,
        'branch_id' => $ctx['branch']->id,
    ]);
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.movement.created',
        'auditable_id' => $movement->id,
        'branch_id' => $ctx['branch']->id,
    ]);

    // ----- Response payload -----
    expect($response->json('data.quantity'))->toBe('1.000');
    // total_cost = 1.000 * 1.500 = 1.500 (computed at render time).
    expect($response->json('data.total_cost'))->toBe('1.500');
    expect($response->json('data.reason'))->toBe('spoiled');
    expect($response->json('data.ingredient.name'))->toBe('Whole Milk');
    expect($response->json('data.branch.uuid'))->toBe($ctx['branch']->uuid);
});

// =================== VALIDATION ===================

it('returns 422 when reason is other but notes is empty', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    BranchStock::factory()
        ->for($ctx['branch'], 'branch')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '5.000']);

    // The 'other' reason is the escape hatch — the Action
    // enforces a non-empty notes field so the audit trail
    // doesn't reduce to a useless "other" with no explanation.
    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '1.000',
        'reason' => WasteReason::Other->value,
        // notes deliberately omitted
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Notes');

    // Nothing persisted on the rejected path.
    expect(WasteRecord::query()->count())->toBe(0);
    expect(StockMovement::query()->count())->toBe(0);
});

it('returns 422 when waste quantity exceeds the current branch_stock balance', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    // Only 2.000 on hand; trying to waste 3.000.
    BranchStock::factory()
        ->for($ctx['branch'], 'branch')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '2.000']);

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '3.000',
        'reason' => WasteReason::Spoiled->value,
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Not enough stock');

    expect(WasteRecord::query()->count())->toBe(0);
    expect(StockMovement::query()->count())->toBe(0);
});

it('returns 422 when waste quantity is zero or negative', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '0',
        'reason' => WasteReason::Spoiled->value,
    ])->assertStatus(422)->assertJsonValidationErrors(['quantity']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '-1.000',
        'reason' => WasteReason::Spoiled->value,
    ])->assertStatus(422)->assertJsonValidationErrors(['quantity']);
});

it('returns 422 when reason is not in the WasteReason enum', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Bogus reason — Rule::in($enum->values()) should reject.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '1.000',
        'reason' => 'definitely_not_a_reason',
    ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
});

// =================== CROSS-TENANT ===================

it('returns 422 when the ingredient uuid belongs to another company', function (): void {
    $ctx = makeMerchantActor();
    // Foreign ingredient — controller's tenant-scoped resolve
    // returns null → 422 "Ingredient not found". This is the
    // first line of defence; the Action does the same check.
    $otherCompany = Company::factory()->create();
    $foreignIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $foreignIngredient->uuid,
        'quantity' => '1.000',
        'reason' => WasteReason::Spoiled->value,
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Ingredient');
});

it('returns 404 when the branch uuid belongs to another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($otherCompany, 'company')->create();
    $foreignIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();

    // Branch ownership check fires first → 404 (resource hiding).
    $this->postJson("/api/branches/{$foreignBranch->uuid}/waste", [
        'ingredient_uuid' => $foreignIngredient->uuid,
        'quantity' => '1.000',
        'reason' => WasteReason::Spoiled->value,
    ])->assertNotFound();
});

// =================== INDEX + FILTERS ===================

it('lists waste records paginated + scoped to the route branch + with ingredient loaded', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Milk']);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Beans']);

    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($milk, 'ingredient')->count(2)->create();
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($beans, 'ingredient')->create();

    // Cross-tenant data MUST NOT leak through.
    $otherCompany = Company::factory()->create();
    $otherBranch = Branch::factory()->for($otherCompany, 'company')->create();
    $otherIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();
    WasteRecord::factory()->for($otherBranch, 'branch')->for($otherIngredient, 'ingredient')->create();

    $response = $this->getJson("/api/branches/{$ctx['branch']->uuid}/waste")->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(3);
    // Paginator metadata is present (the controller returns
    // LengthAwarePaginator → JSON includes current_page etc.).
    expect($response->json('current_page'))->toBe(1);

    // ingredient eager-loaded on every row.
    foreach ($data as $row) {
        expect($row)->toHaveKey('ingredient');
        expect($row['ingredient'])->not->toBeNull();
    }
});

it('filters waste records by reason via ?reason query and fails-closed on unknown reason', function (): void {
    $ctx = makeMerchantActor();
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($ingredient, 'ingredient')->expired()->create();
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($ingredient, 'ingredient')->create(); // spoiled (default)

    $expired = $this->getJson("/api/branches/{$ctx['branch']->uuid}/waste?reason=expired")->assertOk();
    expect($expired->json('data'))->toHaveCount(1);
    expect($expired->json('data.0.reason'))->toBe('expired');

    // Unknown reason fails closed (zero rows, no leak / no error).
    $unknown = $this->getJson("/api/branches/{$ctx['branch']->uuid}/waste?reason=bogus")->assertOk();
    expect($unknown->json('data'))->toHaveCount(0);
});

it('filters waste records by ingredient uuid and silently returns zero rows for a cross-tenant uuid', function (): void {
    $ctx = makeMerchantActor();
    $mine = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $other = Ingredient::factory()->for($ctx['company'], 'company')->create();
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($mine, 'ingredient')->count(2)->create();
    WasteRecord::factory()->for($ctx['branch'], 'branch')->for($other, 'ingredient')->create();

    $byMine = $this->getJson("/api/branches/{$ctx['branch']->uuid}/waste?ingredient={$mine->uuid}")->assertOk();
    expect($byMine->json('data'))->toHaveCount(2);

    // Cross-tenant uuid resolves to id null → -1 sentinel →
    // zero rows. The endpoint must not 404 or leak that the
    // uuid exists elsewhere.
    $otherCompany = Company::factory()->create();
    $foreignIngredient = Ingredient::factory()->for($otherCompany, 'company')->create();
    $cross = $this->getJson("/api/branches/{$ctx['branch']->uuid}/waste?ingredient={$foreignIngredient->uuid}")->assertOk();
    expect($cross->json('data'))->toHaveCount(0);
});

// =================== PERMISSION GATES ===================

it('lets a Viewer list waste records but forbids recording one', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Viewer has inventory.view → list 200.
    $this->getJson("/api/branches/{$ctx['branch']->uuid}/waste")->assertOk();

    // But not inventory.manage → record forbidden.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '1.000',
        'reason' => WasteReason::Spoiled->value,
    ])->assertForbidden();
});

it('forbids a CashierSupervisor from recording waste (no inventory.manage)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '1.000',
        'reason' => WasteReason::Spoiled->value,
    ])->assertForbidden();
});

it('lets an InventoryManager record waste (has inventory.manage)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create();
    BranchStock::factory()
        ->for($ctx['branch'], 'branch')
        ->for($ingredient, 'ingredient')
        ->create(['quantity' => '5.000']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/waste", [
        'ingredient_uuid' => $ingredient->uuid,
        'quantity' => '1.000',
        'reason' => WasteReason::Spoiled->value,
    ])->assertCreated();
});
