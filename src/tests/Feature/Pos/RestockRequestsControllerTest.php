<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 5c RestockRequestsController + the
 * full Create / Update / Submit / Approve / Reject / Cancel /
 * Allocate lifecycle.
 *
 * The state machine:
 *
 *   Draft ──Submit──> Submitted ──Approve──> Approved ──Allocate──> Fulfilled
 *     │                  │                                              ^
 *     │                  └──Reject──> Rejected                          │
 *     └──Cancel──> Cancelled  (also from Submitted; NOT from Approved)
 *
 * Covers:
 *   - LIFECYCLE: create draft + audit, submit (sets submitted_at),
 *     approve (sets reviewed_by + reviewed_at), reject (mandates
 *     non-empty review_note), cancel (Draft + Submitted only),
 *     allocate (writes signed-POSITIVE stock movements at the
 *     REQUESTING branch + sets quantity_allocated + transitions
 *     to Fulfilled + sets fulfilled_at). The allocate step is
 *     the critical "two-writes-one-transaction" path — a per-line
 *     restock movement plus the request status flip.
 *   - UPDATE: only legal on Draft; idempotent on same shape.
 *   - ALLOCATION EDGES: per-line override map (partial), 0 →
 *     skip the movement but save quantity_allocated=0, over-cap
 *     → 422, foreign line id → 422.
 *   - STATUS GUARDS: submit non-Draft → 422, allocate non-
 *     Approved → 422, cancel Approved/Fulfilled/Rejected → 422.
 *   - VALIDATION: duplicate ingredient_uuid → 422, empty lines
 *     → 422, non-positive quantity_requested → 422.
 *   - CROSS-TENANT: foreign ingredient → 422 (create), foreign
 *     branch → 404 (create), foreign request → 404 on every
 *     lifecycle endpoint.
 *   - INDEX FILTERS: by status, by branch (unknown / cross-tenant
 *     → zero rows, no leak).
 *   - PERMISSION MATRIX: Viewer forbidden everywhere on writes,
 *     InventoryManager can run the full lifecycle end-to-end,
 *     SuperAdmin via the auto-full grant.
 */

use App\Enums\MerchantRole;
use App\Enums\RestockRequestStatus;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== LIFECYCLE: HAPPY PATH ===================

it('creates a draft request with N lines + writes inventory.restock_request.created audit row', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity_requested' => '5.000'],
            ['ingredient_uuid' => $beans->uuid, 'quantity_requested' => '2.500', 'note' => 'urgent'],
        ],
        'note' => 'for the weekend rush',
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('draft');
    expect($response->json('data.is_terminal'))->toBeFalse();
    expect($response->json('data.lines'))->toHaveCount(2);
    expect($response->json('data.note'))->toBe('for the weekend rush');

    $req = RestockRequest::query()
        ->where('company_id', $ctx['company']->id)
        ->firstOrFail();
    expect($req->status)->toBe(RestockRequestStatus::Draft);
    expect($req->submitted_at)->toBeNull();
    expect((int) $req->requested_by_user_id)->toBe($ctx['user']->id);
    expect($req->lines()->count())->toBe(2);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.restock_request.created',
        'auditable_id' => $req->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('submits a draft request which sets submitted_at + writes the audit row + transitions to submitted', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    RestockRequestLine::factory()->for($req, 'request')->for($ing, 'ingredient')->create();

    $response = $this->postJson("/api/restock-requests/{$req->uuid}/submit")->assertOk();

    expect($response->json('data.status'))->toBe('submitted');
    expect($response->json('data.submitted_at'))->not->toBeNull();

    $req->refresh();
    expect($req->status)->toBe(RestockRequestStatus::Submitted);
    expect($req->submitted_at)->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.restock_request.submitted',
        'auditable_id' => $req->id,
    ]);
});

it('approves a submitted request which sets reviewed_by + reviewed_at + transitions to approved', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->submitted()->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    RestockRequestLine::factory()->for($req, 'request')->for($ing, 'ingredient')->create();

    $response = $this->postJson("/api/restock-requests/{$req->uuid}/approve", [
        'note' => 'sending tomorrow',
    ])->assertOk();

    expect($response->json('data.status'))->toBe('approved');

    $req->refresh();
    expect($req->status)->toBe(RestockRequestStatus::Approved);
    expect((int) $req->reviewed_by_user_id)->toBe($ctx['user']->id);
    expect($req->reviewed_at)->not->toBeNull();
    expect($req->review_note)->toBe('sending tomorrow');
});

it('rejects a submitted request and requires a non-empty review_note', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->submitted()->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    RestockRequestLine::factory()->for($req, 'request')->for($ing, 'ingredient')->create();

    // Empty note → 422 (the Action throws; controller relays).
    $empty = $this->postJson("/api/restock-requests/{$req->uuid}/reject", [
        'note' => '',
    ])->assertStatus(422);
    expect($empty->json('message'))->toContain('rejection note');

    // With a real reason → ok.
    $this->postJson("/api/restock-requests/{$req->uuid}/reject", [
        'note' => "We're out too, try next month",
    ])->assertOk();

    $req->refresh();
    expect($req->status)->toBe(RestockRequestStatus::Rejected);
    expect($req->review_note)->toBe("We're out too, try next month");
});

it('cancels a draft or submitted request and stores the note on review_note', function (): void {
    $ctx = makeMerchantActor();

    // Draft → cancellable.
    $draft = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $this->postJson("/api/restock-requests/{$draft->uuid}/cancel", [
        'note' => 'changed our mind',
    ])->assertOk();
    $draft->refresh();
    expect($draft->status)->toBe(RestockRequestStatus::Cancelled);
    // We reuse review_note as the cancellation reason store.
    expect($draft->review_note)->toBe('changed our mind');

    // Submitted → still cancellable.
    $submitted = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->submitted()->create();
    $this->postJson("/api/restock-requests/{$submitted->uuid}/cancel")->assertOk();
    $submitted->refresh();
    expect($submitted->status)->toBe(RestockRequestStatus::Cancelled);
});

it('allocates an approved request which writes one positive type=restock stock_movement per non-zero line + sets quantity_allocated + transitions to fulfilled + sets fulfilled_at', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->approved()->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create(['default_unit_cost' => '1.500']);
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create(['default_unit_cost' => '15.000']);

    $milkLine = RestockRequestLine::factory()->for($req, 'request')->for($milk, 'ingredient')
        ->create(['quantity_requested' => '5.000']);
    $beansLine = RestockRequestLine::factory()->for($req, 'request')->for($beans, 'ingredient')
        ->create(['quantity_requested' => '2.000']);

    // No allocations override → full requested quantities.
    $response = $this->postJson("/api/restock-requests/{$req->uuid}/allocate", [])->assertOk();

    expect($response->json('data.status'))->toBe('fulfilled');
    expect($response->json('data.fulfilled_at'))->not->toBeNull();

    $req->refresh();
    expect($req->status)->toBe(RestockRequestStatus::Fulfilled);
    expect($req->fulfilled_at)->not->toBeNull();

    // Per-line allocated values persisted.
    $milkLine->refresh();
    $beansLine->refresh();
    expect((string) $milkLine->quantity_allocated)->toBe('5.000');
    expect((string) $beansLine->quantity_allocated)->toBe('2.000');

    // One stock_movement per non-zero allocation, signed-POSITIVE
    // (restocks are inflows), at the REQUESTING branch (not HQ
    // or some other branch — defends against a "send from branch
    // X" bug shipping inventory to the wrong location).
    $milkMovement = StockMovement::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $milk->id)
        ->where('movement_type', StockMovementType::Restock->value)
        ->firstOrFail();
    expect((string) $milkMovement->quantity)->toBe('5.000');
    expect($milkMovement->reference_type)->toBe(RestockRequestLine::class);
    expect((int) $milkMovement->reference_id)->toBe($milkLine->id);

    $beansMovement = StockMovement::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $beans->id)
        ->where('movement_type', StockMovementType::Restock->value)
        ->firstOrFail();
    expect((string) $beansMovement->quantity)->toBe('2.000');
    expect((int) $beansMovement->reference_id)->toBe($beansLine->id);

    // Branch stock incremented (restock is INFLOW). 0 → 5.000 and 0 → 2.000.
    $milkBalance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $milk->id)
        ->firstOrFail();
    expect((string) $milkBalance->quantity)->toBe('5.000');

    $beansBalance = BranchStock::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $beans->id)
        ->firstOrFail();
    expect((string) $beansBalance->quantity)->toBe('2.000');

    // Audit row for the allocation event.
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.restock_request.allocated',
        'auditable_id' => $req->id,
    ]);
});

// =================== UPDATE (DRAFT ONLY) ===================

it('updates the lines on a Draft request idempotently (same shape skips audit)', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();
    RestockRequestLine::factory()->for($req, 'request')->for($milk, 'ingredient')
        ->create(['quantity_requested' => '5.000']);

    // PATCH with the SAME shape — should no-op (no audit row).
    $this->patchJson("/api/restock-requests/{$req->uuid}", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity_requested' => '5.000'],
        ],
    ])->assertOk();

    $audits = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'inventory.restock_request.updated')
        ->where('auditable_id', $req->id)
        ->count();
    expect($audits)->toBe(0);

    // PATCH with a DIFFERENT quantity — should write the audit
    // row + persist the change.
    $this->patchJson("/api/restock-requests/{$req->uuid}", [
        'lines' => [
            ['ingredient_uuid' => $milk->uuid, 'quantity_requested' => '7.500'],
        ],
    ])->assertOk();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'inventory.restock_request.updated',
        'auditable_id' => $req->id,
    ]);

    $line = RestockRequestLine::query()->where('restock_request_id', $req->id)->firstOrFail();
    expect((string) $line->quantity_requested)->toBe('7.500');
});

it('returns 422 when trying to update a non-Draft request (any other status is locked)', function (): void {
    $ctx = makeMerchantActor();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // For each non-draft status: a PATCH must fail with 422.
    foreach ([
        'submitted',
        'approved',
        'fulfilled',
        'rejected',
        'cancelled',
    ] as $status) {
        $factory = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch');
        $req = match ($status) {
            'submitted' => $factory->submitted()->create(),
            'approved' => $factory->approved()->create(),
            'fulfilled' => $factory->fulfilled()->create(),
            'rejected' => $factory->rejected()->create(),
            'cancelled' => $factory->cancelled()->create(),
        };
        RestockRequestLine::factory()->for($req, 'request')->for($milk, 'ingredient')->create();

        $response = $this->patchJson("/api/restock-requests/{$req->uuid}", [
            'lines' => [['ingredient_uuid' => $milk->uuid, 'quantity_requested' => '1.000']],
        ])->assertStatus(422);
        expect($response->json('message'))->toContain('Draft');
    }
});

// =================== ALLOCATION EDGE CASES ===================

it('supports a partial allocation via per-line override (less than requested)', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->approved()->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $line = RestockRequestLine::factory()->for($req, 'request')->for($ing, 'ingredient')
        ->create(['quantity_requested' => '10.000']);

    $this->postJson("/api/restock-requests/{$req->uuid}/allocate", [
        // String key is intentional — JSON body always sends
        // numeric keys as strings; the controller normalises
        // back to int line ids.
        'allocations' => [(string) $line->id => '4.000'],
    ])->assertOk();

    $line->refresh();
    // Allocated reflects the override, not the request amount.
    expect((string) $line->quantity_allocated)->toBe('4.000');

    $movement = StockMovement::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('ingredient_id', $ing->id)
        ->firstOrFail();
    expect((string) $movement->quantity)->toBe('4.000');
});

it('skips writing a stock_movement for a line allocated 0 but still saves quantity_allocated=0', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->approved()->create();
    $milk = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $beans = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $milkLine = RestockRequestLine::factory()->for($req, 'request')->for($milk, 'ingredient')
        ->create(['quantity_requested' => '5.000']);
    $beansLine = RestockRequestLine::factory()->for($req, 'request')->for($beans, 'ingredient')
        ->create(['quantity_requested' => '2.000']);

    $this->postJson("/api/restock-requests/{$req->uuid}/allocate", [
        'allocations' => [
            (string) $milkLine->id => '5.000', // full
            (string) $beansLine->id => '0',    // skip
        ],
    ])->assertOk();

    $milkLine->refresh();
    $beansLine->refresh();
    expect((string) $milkLine->quantity_allocated)->toBe('5.000');
    // Zero-allocated line is still persisted as 0.000 (we
    // recorded the intentional "we approved but couldn't send"
    // state on the line for reporting).
    expect((string) $beansLine->quantity_allocated)->toBe('0.000');

    // But NO movement for the zero-allocated line.
    expect(StockMovement::query()->where('ingredient_id', $beans->id)->count())->toBe(0);
    // One movement for the full-allocated line.
    expect(StockMovement::query()->where('ingredient_id', $milk->id)->count())->toBe(1);
});

it('returns 422 when an allocation override exceeds quantity_requested', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->approved()->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $line = RestockRequestLine::factory()->for($req, 'request')->for($ing, 'ingredient')
        ->create(['quantity_requested' => '5.000']);

    $response = $this->postJson("/api/restock-requests/{$req->uuid}/allocate", [
        'allocations' => [(string) $line->id => '10.000'], // over the cap
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('exceeds');

    // Status untouched on the rejected path (still Approved).
    $req->refresh();
    expect($req->status)->toBe(RestockRequestStatus::Approved);
});

it('returns 422 when allocations contain a line id not belonging to this request', function (): void {
    $ctx = makeMerchantActor();
    $reqA = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->approved()->create();
    $reqB = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->approved()->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $lineA = RestockRequestLine::factory()->for($reqA, 'request')->for($ing, 'ingredient')->create();
    $lineB = RestockRequestLine::factory()->for($reqB, 'request')->for($ing, 'ingredient')->create();

    // Try to allocate request A using request B's line id.
    $response = $this->postJson("/api/restock-requests/{$reqA->uuid}/allocate", [
        'allocations' => [(string) $lineB->id => '1.000'],
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('does not belong');
});

// =================== STATUS GUARDS ===================

it('returns 422 when submitting a non-Draft request', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->submitted()->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    RestockRequestLine::factory()->for($req, 'request')->for($ing, 'ingredient')->create();

    $response = $this->postJson("/api/restock-requests/{$req->uuid}/submit")->assertStatus(422);
    expect($response->json('message'))->toContain('Draft');
});

it('returns 422 when allocating a non-Approved request', function (): void {
    $ctx = makeMerchantActor();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->submitted()->create();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    RestockRequestLine::factory()->for($req, 'request')->for($ing, 'ingredient')->create();

    $response = $this->postJson("/api/restock-requests/{$req->uuid}/allocate", [])->assertStatus(422);
    expect($response->json('message'))->toContain('Approved');
});

it('returns 422 when cancelling an Approved / Fulfilled / Rejected request', function (): void {
    $ctx = makeMerchantActor();
    foreach (['approved', 'fulfilled', 'rejected'] as $status) {
        $factory = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch');
        $req = match ($status) {
            'approved' => $factory->approved()->create(),
            'fulfilled' => $factory->fulfilled()->create(),
            'rejected' => $factory->rejected()->create(),
        };

        $response = $this->postJson("/api/restock-requests/{$req->uuid}/cancel")->assertStatus(422);
        expect($response->json('message'))->toContain('Draft or Submitted');
    }
});

// =================== VALIDATION ===================

it('returns 422 on duplicate ingredient_uuid in the create payload', function (): void {
    $ctx = makeMerchantActor();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [
            ['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '1.000'],
            ['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '2.000'],
        ],
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('Duplicate');

    // No partial state — nothing persisted on the rollback.
    expect(RestockRequest::query()->count())->toBe(0);
    expect(RestockRequestLine::query()->count())->toBe(0);
});

it('returns 422 on an empty lines array', function (): void {
    $ctx = makeMerchantActor();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines']);
});

it('returns 422 on non-positive quantity_requested', function (): void {
    $ctx = makeMerchantActor();
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Zero qty — caught by form-request rule gt:0.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '0']],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity_requested']);

    // Negative qty — same rule.
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '-1.000']],
    ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.quantity_requested']);
});

// =================== CROSS-TENANT ===================

it('returns 422 when an ingredient_uuid in the create payload belongs to another company', function (): void {
    $ctx = makeMerchantActor();
    $mine = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $otherCompany = Company::factory()->create();
    $foreign = Ingredient::factory()->for($otherCompany, 'company')->create();

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [
            ['ingredient_uuid' => $mine->uuid, 'quantity_requested' => '1.000'],
            ['ingredient_uuid' => $foreign->uuid, 'quantity_requested' => '2.000'],
        ],
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('do not belong');

    // Full rollback — even the legitimate line is not persisted.
    expect(RestockRequest::query()->count())->toBe(0);
});

it('returns 404 when creating a request against a foreign-tenant branch', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($otherCompany, 'company')->create();
    $foreignIng = Ingredient::factory()->for($otherCompany, 'company')->create();

    $this->postJson("/api/branches/{$foreignBranch->uuid}/restock-requests", [
        'lines' => [['ingredient_uuid' => $foreignIng->uuid, 'quantity_requested' => '1.000']],
    ])->assertNotFound();
});

it('returns 404 when targeting a foreign-tenant request on every lifecycle endpoint', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $otherBranch = Branch::factory()->for($otherCompany, 'company')->create();
    $foreignIng = Ingredient::factory()->for($otherCompany, 'company')->create();

    // Foreign request in each interesting state so EVERY
    // endpoint exercises its own refuseIfNotInTenant check.
    $foreignDraft = RestockRequest::factory()->for($otherCompany, 'company')->for($otherBranch, 'branch')->create();
    RestockRequestLine::factory()->for($foreignDraft, 'request')->for($foreignIng, 'ingredient')->create();
    $foreignSubmitted = RestockRequest::factory()->for($otherCompany, 'company')->for($otherBranch, 'branch')->submitted()->create();
    RestockRequestLine::factory()->for($foreignSubmitted, 'request')->for($foreignIng, 'ingredient')->create();
    $foreignApproved = RestockRequest::factory()->for($otherCompany, 'company')->for($otherBranch, 'branch')->approved()->create();
    RestockRequestLine::factory()->for($foreignApproved, 'request')->for($foreignIng, 'ingredient')->create();

    // show
    $this->getJson("/api/restock-requests/{$foreignDraft->uuid}")->assertNotFound();
    // update (PATCH)
    $this->patchJson("/api/restock-requests/{$foreignDraft->uuid}", [
        'lines' => [['ingredient_uuid' => $foreignIng->uuid, 'quantity_requested' => '1.000']],
    ])->assertNotFound();
    // submit
    $this->postJson("/api/restock-requests/{$foreignDraft->uuid}/submit")->assertNotFound();
    // approve
    $this->postJson("/api/restock-requests/{$foreignSubmitted->uuid}/approve")->assertNotFound();
    // reject
    $this->postJson("/api/restock-requests/{$foreignSubmitted->uuid}/reject", ['note' => 'no'])->assertNotFound();
    // cancel
    $this->postJson("/api/restock-requests/{$foreignDraft->uuid}/cancel")->assertNotFound();
    // allocate
    $this->postJson("/api/restock-requests/{$foreignApproved->uuid}/allocate", [])->assertNotFound();
});

// =================== INDEX + FILTERS ===================

it('filters the index by status and by branch (unknown / cross-tenant → zero rows)', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();

    RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->count(2)->create();
    RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->submitted()->create();
    RestockRequest::factory()->for($ctx['company'], 'company')->for($branchB, 'branch')->approved()->create();

    // No filter → 4.
    expect($this->getJson('/api/restock-requests')->assertOk()->json('data'))->toHaveCount(4);

    // Filter by status=draft → 2.
    expect(
        $this->getJson('/api/restock-requests?status=draft')->assertOk()->json('data'),
    )->toHaveCount(2);

    // Filter by branch (the original ctx branch) → 3.
    expect(
        $this->getJson("/api/restock-requests?branch={$ctx['branch']->uuid}")->assertOk()->json('data'),
    )->toHaveCount(3);

    // Unknown status → 0 (fail-closed, no error).
    expect(
        $this->getJson('/api/restock-requests?status=bogus')->assertOk()->json('data'),
    )->toHaveCount(0);

    // Cross-tenant branch uuid → 0 (no leak).
    $otherCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($otherCompany, 'company')->create();
    expect(
        $this->getJson("/api/restock-requests?branch={$foreignBranch->uuid}")->assertOk()->json('data'),
    )->toHaveCount(0);

    // Cross-tenant data isolation: foreign requests must not
    // appear in the unfiltered list.
    RestockRequest::factory()->for($otherCompany, 'company')->for($foreignBranch, 'branch')->create();
    expect(
        $this->getJson('/api/restock-requests')->assertOk()->json('data'),
    )->toHaveCount(4);
});

// =================== PERMISSION MATRIX ===================

it('forbids a Viewer from creating + submitting + cancelling (no inventory.restock_request.create)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $req = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create();
    RestockRequestLine::factory()->for($req, 'request')->for($ing, 'ingredient')->create();

    // create
    $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '1.000']],
    ])->assertForbidden();

    // update + submit + cancel — all gate on the same permission.
    $this->patchJson("/api/restock-requests/{$req->uuid}", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '1.000']],
    ])->assertForbidden();
    $this->postJson("/api/restock-requests/{$req->uuid}/submit")->assertForbidden();
    $this->postJson("/api/restock-requests/{$req->uuid}/cancel")->assertForbidden();
});

it('forbids a Viewer from approving + rejecting + allocating (no inventory.restock_request.review)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();
    $submitted = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->submitted()->create();
    RestockRequestLine::factory()->for($submitted, 'request')->for($ing, 'ingredient')->create();
    $approved = RestockRequest::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->approved()->create();
    RestockRequestLine::factory()->for($approved, 'request')->for($ing, 'ingredient')->create();

    $this->postJson("/api/restock-requests/{$submitted->uuid}/approve")->assertForbidden();
    $this->postJson("/api/restock-requests/{$submitted->uuid}/reject", ['note' => 'no'])->assertForbidden();
    $this->postJson("/api/restock-requests/{$approved->uuid}/allocate", [])->assertForbidden();
});

it('lets an InventoryManager run the full lifecycle end-to-end', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create(['default_unit_cost' => '2.000']);

    // create
    $created = $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '5.000']],
    ])->assertCreated();
    $uuid = $created->json('data.uuid');

    // submit
    $this->postJson("/api/restock-requests/{$uuid}/submit")->assertOk();

    // approve
    $this->postJson("/api/restock-requests/{$uuid}/approve")->assertOk();

    // allocate (full requested amount)
    $this->postJson("/api/restock-requests/{$uuid}/allocate", [])->assertOk();

    $req = RestockRequest::query()->where('uuid', $uuid)->firstOrFail();
    expect($req->status)->toBe(RestockRequestStatus::Fulfilled);
    expect(StockMovement::query()->where('ingredient_id', $ing->id)->count())->toBe(1);
});

it('lets the SuperAdmin do everything via the auto-full permission grant', function (): void {
    $ctx = makeMerchantActor(); // default = SuperAdmin
    $ing = Ingredient::factory()->for($ctx['company'], 'company')->create();

    // Just hit one create + one review endpoint to exercise both
    // permission gates without re-running the full chain.
    $created = $this->postJson("/api/branches/{$ctx['branch']->uuid}/restock-requests", [
        'lines' => [['ingredient_uuid' => $ing->uuid, 'quantity_requested' => '1.000']],
    ])->assertCreated();
    $uuid = $created->json('data.uuid');
    $this->postJson("/api/restock-requests/{$uuid}/submit")->assertOk();
    $this->postJson("/api/restock-requests/{$uuid}/approve")->assertOk();
});
