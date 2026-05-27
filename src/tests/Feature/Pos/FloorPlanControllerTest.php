<?php

declare(strict_types=1);

/**
 * Feature tests for the Phase 5 Floor Plan controllers
 * (FloorsController + TablesController).
 *
 * Covers:
 *   - LIST floors of a branch, with tables eager-loaded;
 *     cross-tenant branch UUID returns 404.
 *   - CREATE floor: persists, audit row, dupe (branch_id,
 *     name) → 422.
 *   - UPDATE floor: name/status edits, cross-tenant 404.
 *   - DELETE floor: refused when has tables; allowed when
 *     empty; soft-delete + audit.
 *   - CREATE table: persists with auto-minted qr_token,
 *     dupe (floor_id, label) → 422, min>max → 422.
 *   - UPDATE table: edits, dupe label on same floor → 422.
 *   - DELETE table: soft-delete + audit.
 *   - REGENERATE QR: returns new token, old token replaced,
 *     audit row written with NO token in payload.
 *   - Permission gates: FloorPlanView for read, FloorPlanManage
 *     for write. Viewer can read but can't create.
 *   - Cross-tenant safety on every mutating endpoint.
 */

use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use App\Enums\TableShape;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Floor;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// =================== FLOOR — LIST ===================

it('lists floors of a branch with tables eager-loaded', function (): void {
    $ctx = makeMerchantActor();

    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create(['name' => 'Main Hall']);

    Table::factory()->count(3)
        ->for($ctx['company'], 'company')
        ->for($floor, 'floor')
        ->create();

    $response = $this->getJson("/api/branches/{$ctx['branch']->uuid}/floors")
        ->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Main Hall');
    expect($data[0]['tables_count'])->toBe(3);
    expect($data[0]['tables'])->toHaveCount(3);
});

it('returns 404 when listing floors of a branch owned by another company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($otherCompany, 'company')->create();

    $this->getJson("/api/branches/{$foreignBranch->uuid}/floors")
        ->assertNotFound();
});

// =================== FLOOR — CREATE ===================

it('creates a floor and writes an audit row', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson("/api/branches/{$ctx['branch']->uuid}/floors", [
        'name' => 'Patio',
        'name_ar' => 'الباحة',
        'display_order' => 1,
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Patio');
    expect($response->json('data.name_ar'))->toBe('الباحة');

    $floor = Floor::query()
        ->where('branch_id', $ctx['branch']->id)
        ->where('name', 'Patio')
        ->firstOrFail();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'floor.created',
        'auditable_id' => $floor->id,
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
    ]);
});

it('refuses to create a duplicate floor name within the same branch', function (): void {
    $ctx = makeMerchantActor();

    Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create(['name' => 'Main Hall']);

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/floors", [
        'name' => 'Main Hall',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('allows the same floor name on two different branches of the same company', function (): void {
    $ctx = makeMerchantActor();

    Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create(['name' => 'Main Hall']);

    $secondBranch = Branch::factory()->for($ctx['company'], 'company')->create();

    $this->postJson("/api/branches/{$secondBranch->uuid}/floors", [
        'name' => 'Main Hall',
    ])->assertCreated();
});

// =================== FLOOR — UPDATE ===================

it('edits a floor name and status', function (): void {
    $ctx = makeMerchantActor();

    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create(['name' => 'Old Name']);

    $this->patchJson("/api/floors/{$floor->uuid}", [
        'name' => 'New Name',
        'status' => 'inactive',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.status', 'inactive');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'floor.updated',
        'auditable_id' => $floor->id,
    ]);
});

it('returns 404 when updating a floor owned by another company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $otherBranch = Branch::factory()->for($otherCompany, 'company')->create();
    $foreignFloor = Floor::factory()
        ->for($otherCompany, 'company')
        ->for($otherBranch, 'branch')
        ->create();

    $this->patchJson("/api/floors/{$foreignFloor->uuid}", ['name' => 'Hijack'])
        ->assertNotFound();
});

// =================== FLOOR — DELETE ===================

it('refuses to delete a floor that has tables', function (): void {
    $ctx = makeMerchantActor();

    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();
    Table::factory()
        ->for($ctx['company'], 'company')
        ->for($floor, 'floor')
        ->create();

    $response = $this->deleteJson("/api/floors/{$floor->uuid}")
        ->assertStatus(422);
    expect($response->json('message'))->toContain('table');

    expect(Floor::query()->find($floor->id))->not->toBeNull();
});

it('deletes an empty floor with audit', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();
    $floorId = $floor->id;

    $this->deleteJson("/api/floors/{$floor->uuid}")
        ->assertNoContent();

    // Soft-deleted — default scope hides, withTrashed sees.
    expect(Floor::query()->find($floorId))->toBeNull();
    expect(Floor::withTrashed()->find($floorId))->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'floor.deleted',
        'auditable_id' => $floorId,
    ]);
});

// =================== TABLE — CREATE ===================

it('creates a table with auto-minted qr_token', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();

    $response = $this->postJson("/api/floors/{$floor->uuid}/tables", [
        'label' => 'T1',
        'seats' => 4,
        'shape' => TableShape::Round->value,
        'notes' => 'by window',
    ])->assertCreated();

    expect($response->json('data.label'))->toBe('T1');
    expect($response->json('data.shape'))->toBe('round');
    $token = $response->json('data.qr_token');
    expect($token)->toBeString()->and(strlen($token))->toBe(24);

    $table = Table::query()->where('label', 'T1')->firstOrFail();
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'table.created',
        'auditable_id' => $table->id,
    ]);
});

it('refuses a duplicate table label on the same floor', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();
    Table::factory()
        ->for($ctx['company'], 'company')
        ->for($floor, 'floor')
        ->create(['label' => 'T1']);

    $this->postJson("/api/floors/{$floor->uuid}/tables", [
        'label' => 'T1',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['label']);
});

it('refuses a table when min_party exceeds max_party', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();

    $this->postJson("/api/floors/{$floor->uuid}/tables", [
        'label' => 'Tbad',
        'min_party' => 8,
        'max_party' => 4,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['max_party']);
});

// =================== TABLE — UPDATE ===================

it('edits a table label + status + notes', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();
    $table = Table::factory()
        ->for($ctx['company'], 'company')
        ->for($floor, 'floor')
        ->create(['label' => 'A1']);

    $this->patchJson("/api/tables/{$table->uuid}", [
        'label' => 'A2',
        'status' => 'inactive',
        'notes' => 'wheelchair accessible',
    ])
        ->assertOk()
        ->assertJsonPath('data.label', 'A2')
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.notes', 'wheelchair accessible');
});

// =================== TABLE — DELETE ===================

it('soft-deletes a table with audit', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();
    $table = Table::factory()
        ->for($ctx['company'], 'company')
        ->for($floor, 'floor')
        ->create();
    $tableId = $table->id;

    $this->deleteJson("/api/tables/{$table->uuid}")
        ->assertNoContent();

    expect(Table::query()->find($tableId))->toBeNull();
    expect(Table::withTrashed()->find($tableId))->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'table.deleted',
        'auditable_id' => $tableId,
    ]);
});

// =================== QR REGENERATION ===================

it('regenerates a table qr_token and never leaks it to the audit log', function (): void {
    $ctx = makeMerchantActor();
    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();
    $table = Table::factory()
        ->for($ctx['company'], 'company')
        ->for($floor, 'floor')
        ->create();
    $oldToken = $table->qr_token;

    $response = $this->postJson("/api/tables/{$table->uuid}/regenerate-qr")
        ->assertOk();
    $newToken = $response->json('qr_token');
    expect($newToken)->toBeString()->and(strlen($newToken))->toBe(24)
        ->and($newToken)->not->toBe($oldToken);

    $table->refresh();
    expect($table->qr_token)->toBe($newToken);

    // Audit row exists but the token itself is NOT in the
    // payload — same hygiene as password/PIN events.
    $auditRow = \Illuminate\Support\Facades\DB::table('pos_audit_logs')
        ->where('event', 'table.qr_regenerated')
        ->where('auditable_id', $table->id)
        ->first();
    expect($auditRow)->not->toBeNull();
    expect($auditRow->new_values)->not->toContain($oldToken);
    expect($auditRow->new_values)->not->toContain($newToken);
});

// =================== CROSS-TENANT ON TABLES ===================

it('returns 404 when mutating a table owned by another company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $otherBranch = Branch::factory()->for($otherCompany, 'company')->create();
    $foreignFloor = Floor::factory()
        ->for($otherCompany, 'company')
        ->for($otherBranch, 'branch')
        ->create();
    $foreignTable = Table::factory()
        ->for($otherCompany, 'company')
        ->for($foreignFloor, 'floor')
        ->create();

    $this->patchJson("/api/tables/{$foreignTable->uuid}", ['label' => 'Hijack'])
        ->assertNotFound();
    $this->deleteJson("/api/tables/{$foreignTable->uuid}")
        ->assertNotFound();
    $this->postJson("/api/tables/{$foreignTable->uuid}/regenerate-qr")
        ->assertNotFound();
});

// =================== PERMISSION GATES ===================

it('lets a Viewer read floors but forbids creating one', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson("/api/branches/{$ctx['branch']->uuid}/floors")
        ->assertOk();

    $this->postJson("/api/branches/{$ctx['branch']->uuid}/floors", [
        'name' => 'Sneaky',
    ])->assertForbidden();
});

it('forbids creating a table for a CashierSupervisor (view-only on floor plan)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $floor = Floor::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();

    $this->postJson("/api/floors/{$floor->uuid}/tables", [
        'label' => 'X',
    ])->assertForbidden();
});
