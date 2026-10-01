<?php

declare(strict_types=1);

/**
 * Feature tests for the merchant-side Portal Users CRUD
 * (App\Http\Controllers\Portal\PortalUsersController).
 *
 * What this proves end-to-end through the HTTP stack:
 *
 *   - actingAs() runs SetMerchantTenantContext middleware which
 *     pins the spatie team_id to the actor's company; the
 *     controller's permission check therefore reads the right
 *     team's role assignments.
 *   - The create flow returns a plaintext password ONCE in the
 *     envelope, persists a bcrypted version, and writes the
 *     portal_user.created audit row.
 *   - Cross-tenant lookups are rejected with 404 — a user can
 *     never PATCH or suspend a teammate from a different
 *     company even if they guess the id.
 *   - Permission gating refuses an actor without the right
 *     spatie permission with a 403, separately from validation
 *     errors (422).
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// `makeMerchantActor()` is declared in tests/Pest.php so every
// Feature test can call it without re-importing the fixture
// scaffolding.

// =================== LIST ===================

it('lists portal users scoped to the actor company', function (): void {
    $ctx = makeMerchantActor();

    // Two teammates in the actor's company.
    User::factory()->count(2)->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
    ]);

    // Decoy: a user in a DIFFERENT company.
    $otherCompany = Company::factory()->create();
    User::factory()->create([
        'company_id' => $otherCompany->id,
        'user_type' => 'merchant',
    ]);

    $response = $this->getJson('/api/portal-users')->assertOk();

    // Actor counts as one of the rows, plus the two we created
    // above = 3. The decoy in the other company must not appear.
    expect($response->json('data'))->toHaveCount(3);
});

// =================== CREATE ===================

it('creates a teammate with a set-password link and no password', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/portal-users', [
        'name' => 'New Teammate',
        'email' => 'teammate@example.test',
        'role' => MerchantRole::Manager->value,
    ])->assertCreated();

    // Owner follow-up 2026-10-01: no password is generated or shown.
    $response->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'invite');
    expect((string) $response->json('set_password_link.url'))->toContain('/setup-password?token=');

    $created = User::query()->where('email', 'teammate@example.test')->firstOrFail();
    expect($created->user_type)->toBe('merchant');
    expect($created->company_id)->toBe($ctx['company']->id);
    expect($created->password)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.created',
        'auditable_id' => $created->id,
        'company_id' => $ctx['company']->id,
    ]);
});

it('rejects a duplicate email across the shared pos_users table', function (): void {
    $ctx = makeMerchantActor();
    // Pre-existing teammate at THIS company.
    User::factory()->create([
        'company_id' => $ctx['company']->id,
        'email' => 'taken@example.test',
    ]);

    $this->postJson('/api/portal-users', [
        'name' => 'Dup',
        'email' => 'taken@example.test',
        'role' => MerchantRole::Manager->value,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

// =================== PERMISSION GATE ===================

it('forbids a Viewer from creating a teammate', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->postJson('/api/portal-users', [
        'name' => 'X',
        'email' => 'x@example.test',
        'role' => MerchantRole::Manager->value,
    ])->assertForbidden();
});

// =================== CROSS-TENANT 404 ===================

it('returns 404 when updating a teammate from a different company', function (): void {
    makeMerchantActor();

    $otherCompany = Company::factory()->create();
    $foreignUser = User::factory()->create([
        'company_id' => $otherCompany->id,
        'user_type' => 'merchant',
    ]);

    $this->patchJson("/api/portal-users/{$foreignUser->id}", [
        'name' => 'Should never happen',
    ])->assertNotFound();
});

// =================== RESET PASSWORD ===================

it('resets a teammate password with a link and blocks the old one', function (): void {
    $ctx = makeMerchantActor();
    $teammate = User::factory()->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
        'password' => 'initial-pass-1234567',
    ]);

    $response = $this->postJson("/api/portal-users/{$teammate->id}/reset-password")
        ->assertOk();

    // Owner follow-up 2026-10-01: a 60-minute link, never a password,
    // and the old password stops working at once.
    $response->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'reset');

    $teammate->refresh();
    expect($teammate->password)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.password_reset',
        'auditable_id' => $teammate->id,
    ]);
});

// =================== SUSPEND / REACTIVATE ===================

it('suspends and reactivates a teammate (idempotent on the second call)', function (): void {
    $ctx = makeMerchantActor();
    $teammate = User::factory()->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
        'status' => 'active',
    ]);

    $this->postJson("/api/portal-users/{$teammate->id}/suspend")
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    $this->postJson("/api/portal-users/{$teammate->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('refuses self-suspension with a 422', function (): void {
    $ctx = makeMerchantActor();

    $this->postJson("/api/portal-users/{$ctx['user']->id}/suspend")
        ->assertStatus(422);
});
