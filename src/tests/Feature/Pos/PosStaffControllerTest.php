<?php

declare(strict_types=1);

/**
 * Feature tests for the merchant POS Staff CRUD
 * (App\Http\Controllers\Pos\PosStaffController).
 *
 * The harness uses the same makeMerchantActor() helper as the
 * Portal Users tests (declared globally by Pest in
 * PortalUsersControllerTest.php) — Pest registers function
 * definitions across the suite so the helper is reachable here.
 */

use App\Enums\MerchantRole;
use App\Enums\StaffPosition;
use App\Enums\StaffStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

// =================== HIRE / CREATE ===================

it('hires a POS staff member with a generated 6-digit PIN returned once', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/pos-staff', [
        'name' => 'Aisha Cashier',
        'branch_id' => $ctx['branch']->id,
        'position' => StaffPosition::Cashier->value,
        'staff_code' => 'CASH-01',
    ])->assertCreated();

    $pin = $response->json('plaintext_pin');
    expect($pin)->toBeString()
        ->and(strlen($pin))->toBe(6)
        ->and(ctype_digit($pin))->toBeTrue();

    $staff = PosStaff::query()->where('staff_code', 'CASH-01')->firstOrFail();
    expect($staff->company_id)->toBe($ctx['company']->id);
    expect($staff->branch_id)->toBe($ctx['branch']->id);
    expect($staff->position)->toBe(StaffPosition::Cashier);
    expect(Hash::check($pin, $staff->pin_hash))->toBeTrue();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'pos_staff.created',
        'auditable_id' => $staff->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('refuses to hire into a branch owned by another company', function (): void {
    makeMerchantActor();

    // A branch belonging to an unrelated company.
    $foreignBranch = Branch::factory()
        ->for(Company::factory()->create(), 'company')
        ->create();

    $this->postJson('/api/pos-staff', [
        'name' => 'Cross-tenant attempt',
        'branch_id' => $foreignBranch->id,
        'position' => StaffPosition::Cashier->value,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_id']);
});

it('rejects a duplicate staff_code within the same company', function (): void {
    $ctx = makeMerchantActor();
    PosStaff::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create(['staff_code' => 'DUP']);

    $this->postJson('/api/pos-staff', [
        'name' => 'Second',
        'branch_id' => $ctx['branch']->id,
        'position' => StaffPosition::Waiter->value,
        'staff_code' => 'DUP',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['staff_code']);
});

// =================== LIST + CROSS-TENANT ===================

it('lists POS staff scoped to the actor company', function (): void {
    $ctx = makeMerchantActor();

    PosStaff::factory()->count(3)
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();

    // Decoy at another company.
    $otherCompany = Company::factory()->create();
    $otherBranch = Branch::factory()->for($otherCompany, 'company')->create();
    PosStaff::factory()->for($otherCompany, 'company')->for($otherBranch, 'branch')->create();

    $response = $this->getJson('/api/pos-staff')->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('returns 404 when updating a staff member from a different company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $otherBranch = Branch::factory()->for($otherCompany, 'company')->create();
    $foreignStaff = PosStaff::factory()
        ->for($otherCompany, 'company')
        ->for($otherBranch, 'branch')
        ->create();

    $this->patchJson("/api/pos-staff/{$foreignStaff->uuid}", [
        'name' => 'Should never happen',
    ])->assertNotFound();
});

// =================== PERMISSION GATE ===================

it('forbids a CashierSupervisor from hiring (only Manager+ can create)', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);

    $this->postJson('/api/pos-staff', [
        'name' => 'X',
        'branch_id' => $ctx['branch']->id,
        'position' => StaffPosition::Cashier->value,
    ])->assertForbidden();
});

it('lets a CashierSupervisor edit existing staff but not terminate', function (): void {
    $ctx = makeMerchantActor(MerchantRole::CashierSupervisor->value);
    $staff = PosStaff::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();

    // update permission granted
    $this->patchJson("/api/pos-staff/{$staff->uuid}", [
        'name' => 'Renamed',
    ])->assertOk();

    // revoke permission denied
    $this->postJson("/api/pos-staff/{$staff->uuid}/terminate")
        ->assertForbidden();
});

// =================== SUSPEND / REACTIVATE / TERMINATE ===================

it('suspends, reactivates, then terminates a staff member', function (): void {
    $ctx = makeMerchantActor();
    $staff = PosStaff::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();

    $this->postJson("/api/pos-staff/{$staff->uuid}/suspend")
        ->assertOk()
        ->assertJsonPath('data.status', StaffStatus::Suspended->value);

    $this->postJson("/api/pos-staff/{$staff->uuid}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', StaffStatus::Active->value);

    $this->postJson("/api/pos-staff/{$staff->uuid}/terminate")
        ->assertOk()
        ->assertJsonPath('data.status', StaffStatus::Terminated->value);

    // Soft-deleted — default query hides it, withTrashed sees it.
    expect(PosStaff::query()->find($staff->id))->toBeNull();
    expect(PosStaff::withTrashed()->find($staff->id))->not->toBeNull();
});

// =================== RESET PIN ===================

it('rotates a staff PIN and returns the new 6-digit plaintext once', function (): void {
    $ctx = makeMerchantActor();
    $staff = PosStaff::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->create();
    $oldHash = $staff->pin_hash;

    $response = $this->postJson("/api/pos-staff/{$staff->uuid}/reset-pin")
        ->assertOk();

    $newPin = $response->json('plaintext_pin');
    expect($newPin)->toBeString()
        ->and(strlen($newPin))->toBe(6)
        ->and(ctype_digit($newPin))->toBeTrue();

    $staff->refresh();
    expect($staff->pin_hash)->not->toBe($oldHash);
    expect(Hash::check($newPin, $staff->pin_hash))->toBeTrue();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'pos_staff.pin_reset',
        'auditable_id' => $staff->id,
    ]);
});

it('cannot reach a terminated staff member through the public API', function (): void {
    // Termination soft-deletes the row, which is exactly what we
    // want — the UUID route binding's default scope excludes
    // trashed rows and returns 404. The action layer's "refuse
    // reset-PIN on terminated" guard is therefore unreachable
    // from a real HTTP request (it's belt-and-braces for an
    // internal caller bypassing the controller).
    $ctx = makeMerchantActor();
    $staff = PosStaff::factory()
        ->for($ctx['company'], 'company')
        ->for($ctx['branch'], 'branch')
        ->terminated()
        ->create();

    $this->postJson("/api/pos-staff/{$staff->uuid}/reset-pin")
        ->assertNotFound();
});
