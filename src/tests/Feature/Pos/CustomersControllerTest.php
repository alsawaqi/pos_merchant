<?php

declare(strict_types=1);

/**
 * Feature tests for Phase 6a CustomersController + the Customer
 * + plate Actions.
 *
 * Covers:
 *   - LIST: paginated, tenant-scoped, search across name + phone
 *     + plate (canonical/uppercase), cross-tenant data isolation
 *   - SHOW: 404 on cross-tenant uuid, plates eager-loaded
 *   - CREATE: happy path + audit row; trims name/phone; rejects
 *     empty fields; duplicate (company_id, phone) → 422;
 *     create-with-initial-plates atomicity (a duplicate plate
 *     aborts the WHOLE create, no partial customer row)
 *   - UPDATE: idempotent diff-aware audit, phone uniqueness re-
 *     checked excluding self, 404 on cross-tenant
 *   - DELETE: soft delete + audit; cross-tenant 404
 *   - PLATE ATTACH: normalises plate (trim + uppercase); duplicate
 *     within tenant → 422; cross-tenant customer → 404
 *   - PLATE DETACH: cross-tenant plate → 404; frees the slot for
 *     a re-attach
 *   - PERMISSION MATRIX:
 *       Viewer            — view OK, manage forbidden
 *       CashierSupervisor — view OK, manage forbidden
 *       InventoryManager  — view OK, manage forbidden (the
 *                            inventory specialist gets View
 *                            only — see 6a-3 commit)
 *       Manager           — full CRUD + plates
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== LIST ===================

it('lists customers paginated, tenant-scoped, with vehicle plates loaded', function (): void {
    $ctx = makeMerchantActor();
    $c1 = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Ahmad Said', 'phone' => '+968 91111111']);
    $c2 = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Bilal Omar', 'phone' => '+968 92222222']);
    CustomerVehiclePlate::factory()->for($c1, 'customer')->for($ctx['company'], 'company')->create(['plate_number' => '12345 A']);

    // Foreign tenant data MUST NOT leak.
    $otherCompany = Company::factory()->create();
    Customer::factory()->for($otherCompany, 'company')->create(['name' => 'Foreign Customer', 'phone' => '+968 99999999']);

    $response = $this->getJson('/api/customers')->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    // Index is paginated → response includes current_page metadata.
    expect($response->json('current_page'))->toBe(1);

    // vehicle_plates eager-loaded for the chip badge.
    $rowA = collect($data)->firstWhere('uuid', $c1->uuid);
    expect($rowA['vehicle_plates'])->toHaveCount(1);
    expect($rowA['vehicle_plates'][0]['plate_number'])->toBe('12345 A');
    expect($rowA['vehicle_plates_count'])->toBe(1);

    $rowB = collect($data)->firstWhere('uuid', $c2->uuid);
    expect($rowB['vehicle_plates'])->toBe([]);
    expect($rowB['vehicle_plates_count'])->toBe(0);
});

it('filters the index by ?search across name + phone + plate (case-insensitive)', function (): void {
    $ctx = makeMerchantActor();
    $byName = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Khalid Al Mahri', 'phone' => '+968 90000001']);
    $byPhone = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Other Person', 'phone' => '+968 91234567']);
    $byPlate = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Third Person', 'phone' => '+968 90000003']);
    CustomerVehiclePlate::factory()->for($byPlate, 'customer')->for($ctx['company'], 'company')->create(['plate_number' => '98765 Z']);

    // Name match (lowercase substring) — only Khalid.
    $r = $this->getJson('/api/customers?search=khalid')->assertOk();
    expect($r->json('data'))->toHaveCount(1);
    expect($r->json('data.0.uuid'))->toBe($byName->uuid);

    // Phone match — only the matching phone.
    $r = $this->getJson('/api/customers?search=1234567')->assertOk();
    expect($r->json('data'))->toHaveCount(1);
    expect($r->json('data.0.uuid'))->toBe($byPhone->uuid);

    // Plate match (search is uppercased on the server; lower-
    // case input still finds it).
    $r = $this->getJson('/api/customers?search=98765')->assertOk();
    expect($r->json('data'))->toHaveCount(1);
    expect($r->json('data.0.uuid'))->toBe($byPlate->uuid);

    // No matches → empty.
    $r = $this->getJson('/api/customers?search=zzzzzz')->assertOk();
    expect($r->json('data'))->toHaveCount(0);
});

// =================== SHOW ===================

it('returns 404 when showing a customer owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreign = Customer::factory()->for($otherCompany, 'company')->create();

    $this->getJson("/api/customers/{$foreign->uuid}")->assertNotFound();
});

it('shows a customer with all their plates eager-loaded', function (): void {
    $ctx = makeMerchantActor();
    $c = Customer::factory()->for($ctx['company'], 'company')->create();
    CustomerVehiclePlate::factory()->for($c, 'customer')->for($ctx['company'], 'company')->create(['plate_number' => 'AAA 11']);
    CustomerVehiclePlate::factory()->for($c, 'customer')->for($ctx['company'], 'company')->create(['plate_number' => 'BBB 22']);

    $response = $this->getJson("/api/customers/{$c->uuid}")->assertOk();
    expect($response->json('data.vehicle_plates'))->toHaveCount(2);
    expect($response->json('data.vehicle_plates_count'))->toBe(2);
});

// =================== CREATE ===================

it('creates a customer and writes an audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/customers', [
        'name' => '  Salim  ', // leading/trailing whitespace
        'phone' => '+968 90000099',
    ])->assertCreated();

    // The Action trims before write.
    expect($response->json('data.name'))->toBe('Salim');
    expect($response->json('data.phone'))->toBe('+968 90000099');

    $row = Customer::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
    expect((int) $row->company_id)->toBe($ctx['company']->id);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'customers.created',
        'auditable_id' => $row->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('returns 422 when creating a duplicate phone within the same tenant', function (): void {
    $ctx = makeMerchantActor();
    Customer::factory()->for($ctx['company'], 'company')->create(['phone' => '+968 90000100']);

    $response = $this->postJson('/api/customers', [
        'name' => 'Duplicate Sam',
        'phone' => '+968 90000100',
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('already exists');
});

it('returns 422 when name or phone is empty', function (): void {
    makeMerchantActor();

    $this->postJson('/api/customers', ['name' => '', 'phone' => '+968 91111111'])
        ->assertStatus(422)->assertJsonValidationErrors(['name']);

    $this->postJson('/api/customers', ['name' => 'X', 'phone' => ''])
        ->assertStatus(422)->assertJsonValidationErrors(['phone']);
});

it('creates with initial plates atomically — a duplicate plate aborts the WHOLE create', function (): void {
    $ctx = makeMerchantActor();
    // Pre-existing customer with a plate that will conflict.
    $existing = Customer::factory()->for($ctx['company'], 'company')->create();
    CustomerVehiclePlate::factory()->for($existing, 'customer')->for($ctx['company'], 'company')
        ->create(['plate_number' => 'DUPE 1']);

    $response = $this->postJson('/api/customers', [
        'name' => 'New Customer',
        'phone' => '+968 90000200',
        'plates' => ['CLEAN 1', 'DUPE 1'], // second one conflicts
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('already attached');

    // The atomic-create promise: no orphan customer + no orphan
    // first-plate sneaked through. Customers count is just the
    // pre-existing one.
    expect(Customer::query()->count())->toBe(1);
    expect(CustomerVehiclePlate::query()->count())->toBe(1);
});

it('creates with initial plates happy path — all plates persist', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/customers', [
        'name' => 'Customer Two Cars',
        'phone' => '+968 90000300',
        'plates' => ['11111 A', '22222 B'],
    ])->assertCreated();

    expect($response->json('data.vehicle_plates'))->toHaveCount(2);
    $plateNumbers = collect($response->json('data.vehicle_plates'))->pluck('plate_number')->all();
    sort($plateNumbers);
    expect($plateNumbers)->toBe(['11111 A', '22222 B']);
});

// =================== UPDATE ===================

it('updates name + phone with diff-aware audit', function (): void {
    $ctx = makeMerchantActor();
    $c = Customer::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Old Name', 'phone' => '+968 90000400']);

    $this->patchJson("/api/customers/{$c->uuid}", [
        'name' => 'New Name',
        'phone' => '+968 90000401',
    ])->assertOk();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'customers.updated',
        'auditable_id' => $c->id,
    ]);
    expect(Customer::query()->find($c->id)->name)->toBe('New Name');
});

it('writes no audit row on a no-op update', function (): void {
    $ctx = makeMerchantActor();
    $c = Customer::factory()->for($ctx['company'], 'company')
        ->create(['name' => 'Same', 'phone' => '+968 90000500']);

    // PATCH with identical values → no audit row.
    $this->patchJson("/api/customers/{$c->uuid}", [
        'name' => 'Same',
        'phone' => '+968 90000500',
    ])->assertOk();

    $audits = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'customers.updated')
        ->where('auditable_id', $c->id)
        ->count();
    expect($audits)->toBe(0);
});

it('returns 422 when updating to a phone already used by another customer in the tenant', function (): void {
    $ctx = makeMerchantActor();
    $a = Customer::factory()->for($ctx['company'], 'company')->create(['phone' => '+968 90000601']);
    $b = Customer::factory()->for($ctx['company'], 'company')->create(['phone' => '+968 90000602']);

    $response = $this->patchJson("/api/customers/{$b->uuid}", [
        'phone' => '+968 90000601', // already used by $a
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('already exists');
});

it('returns 404 when updating a customer owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreign = Customer::factory()->for($otherCompany, 'company')->create();

    $this->patchJson("/api/customers/{$foreign->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

// =================== DELETE ===================

it('soft-deletes a customer with audit', function (): void {
    $ctx = makeMerchantActor();
    $c = Customer::factory()->for($ctx['company'], 'company')->create();
    $cId = $c->id;

    $this->deleteJson("/api/customers/{$c->uuid}")->assertNoContent();

    expect(Customer::query()->find($cId))->toBeNull();
    expect(Customer::withTrashed()->find($cId))->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'customers.deleted',
        'auditable_id' => $cId,
    ]);
});

it('returns 404 when deleting a customer owned by another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreign = Customer::factory()->for($otherCompany, 'company')->create();

    $this->deleteJson("/api/customers/{$foreign->uuid}")->assertNotFound();
});

// =================== PLATE ATTACH ===================

it('attaches a plate normalising whitespace + case (trim, collapse spaces, uppercase)', function (): void {
    $ctx = makeMerchantActor();
    $c = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/customers/{$c->uuid}/plates", [
        // Messy input — leading/trailing space + lowercase + double space.
        'plate_number' => '  12345  a  ',
    ])->assertCreated()
        ->assertJsonPath('data.plate_number', '12345 A');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'customers.plate.attached',
        'company_id' => $ctx['company']->id,
    ]);
});

it('returns 422 on duplicate plate within the same tenant', function (): void {
    $ctx = makeMerchantActor();
    $a = Customer::factory()->for($ctx['company'], 'company')->create();
    $b = Customer::factory()->for($ctx['company'], 'company')->create();
    CustomerVehiclePlate::factory()->for($a, 'customer')->for($ctx['company'], 'company')
        ->create(['plate_number' => 'TAKEN 1']);

    // Try to attach the same plate to a DIFFERENT customer in
    // the same tenant — must fail.
    $response = $this->postJson("/api/customers/{$b->uuid}/plates", [
        'plate_number' => 'taken 1', // even with messy case → normalises to TAKEN 1
    ])->assertStatus(422);
    expect($response->json('message'))->toContain('already attached');
});

it('returns 404 when attaching a plate to a foreign-tenant customer', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreign = Customer::factory()->for($otherCompany, 'company')->create();

    $this->postJson("/api/customers/{$foreign->uuid}/plates", [
        'plate_number' => 'X',
    ])->assertNotFound();
});

// =================== PLATE DETACH ===================

it('detaches a plate, freeing the slot for re-attachment to a different customer', function (): void {
    $ctx = makeMerchantActor();
    $a = Customer::factory()->for($ctx['company'], 'company')->create();
    $b = Customer::factory()->for($ctx['company'], 'company')->create();
    $plate = CustomerVehiclePlate::factory()->for($a, 'customer')->for($ctx['company'], 'company')
        ->create(['plate_number' => 'FREE 1']);

    $this->deleteJson("/api/customer-plates/{$plate->uuid}")->assertNoContent();

    // The unique (company_id, plate_number) slot is now free —
    // attach to a different customer should succeed.
    $this->postJson("/api/customers/{$b->uuid}/plates", [
        'plate_number' => 'FREE 1',
    ])->assertCreated();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'customers.plate.detached',
        'company_id' => $ctx['company']->id,
    ]);
});

it('returns 404 when detaching a plate from another company', function (): void {
    makeMerchantActor();
    $otherCompany = Company::factory()->create();
    $foreignCustomer = Customer::factory()->for($otherCompany, 'company')->create();
    $foreignPlate = CustomerVehiclePlate::factory()->for($foreignCustomer, 'customer')
        ->for($otherCompany, 'company')->create();

    $this->deleteJson("/api/customer-plates/{$foreignPlate->uuid}")->assertNotFound();
});

// =================== PERMISSION MATRIX ===================

it('lets a Viewer list + show customers but forbids any write', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $c = Customer::factory()->for($ctx['company'], 'company')->create();

    // View is OK.
    $this->getJson('/api/customers')->assertOk();
    $this->getJson("/api/customers/{$c->uuid}")->assertOk();

    // Every write is forbidden.
    $this->postJson('/api/customers', ['name' => 'X', 'phone' => '+968 90000700'])->assertForbidden();
    $this->patchJson("/api/customers/{$c->uuid}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/customers/{$c->uuid}")->assertForbidden();
    $this->postJson("/api/customers/{$c->uuid}/plates", ['plate_number' => 'X'])->assertForbidden();
});

it('lets a CashierSupervisor view customers but forbids writes', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $c = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/customers')->assertOk();
    $this->postJson('/api/customers', ['name' => 'X', 'phone' => '+968 90000800'])->assertForbidden();
});

it('lets the InventoryManager view customers but forbids writes (view-only, per role matrix)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::InventoryManager->value);
    $c = Customer::factory()->for($ctx['company'], 'company')->create();

    $this->getJson('/api/customers')->assertOk();
    // Inventory specialist gets View only — manage is a Manager+
    // concern (the customer book isn't an inventory artifact).
    $this->postJson('/api/customers', ['name' => 'X', 'phone' => '+968 90000900'])->assertForbidden();
});

it('lets a Manager run the full customer + plate lifecycle', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);

    $createResponse = $this->postJson('/api/customers', [
        'name' => 'Manager Customer',
        'phone' => '+968 90001000',
    ])->assertCreated();
    $uuid = $createResponse->json('data.uuid');

    $this->patchJson("/api/customers/{$uuid}", ['name' => 'Manager Edited'])->assertOk();
    $attachResponse = $this->postJson("/api/customers/{$uuid}/plates", [
        'plate_number' => 'MGR 1',
    ])->assertCreated();
    $plateUuid = $attachResponse->json('data.uuid');

    $this->deleteJson("/api/customer-plates/{$plateUuid}")->assertNoContent();
    $this->deleteJson("/api/customers/{$uuid}")->assertNoContent();
});
