<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase D7 — self-service profile update (display name only).
 */

it('updates the display name and audits the change', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->forceFill(['name' => 'Old Name'])->save();

    $this->patchJson('/auth/profile', ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('user.name', 'New Name');

    expect($ctx['user']->fresh()->name)->toBe('New Name');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.profile_updated',
        'actor_user_id' => $ctx['user']->id,
        'company_id' => $ctx['company']->id,
        'auditable_id' => $ctx['user']->id,
    ]);
});

it('does not write an audit row when the name is unchanged', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->forceFill(['name' => 'Same Name'])->save();

    $this->patchJson('/auth/profile', ['name' => 'Same Name'])->assertOk();

    $this->assertDatabaseMissing('pos_audit_logs', [
        'event' => 'portal_user.profile_updated',
        'actor_user_id' => $ctx['user']->id,
    ]);
});

it('validates the name', function (): void {
    makeMerchantActor();

    $this->patchJson('/auth/profile', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->patchJson('/auth/profile', ['name' => str_repeat('x', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('ignores attempts to change the email through the profile endpoint', function (): void {
    $ctx = makeMerchantActor();
    $originalEmail = $ctx['user']->email;

    $this->patchJson('/auth/profile', [
        'name' => 'Renamed User',
        'email' => 'takeover@example.com',
    ])->assertOk();

    // Email is not part of the validated payload — it never reaches
    // the action, so the login identifier stays admin-managed.
    expect($ctx['user']->fresh()->email)->toBe($originalEmail);
});

it('rejects guests with 401', function (): void {
    $this->patchJson('/auth/profile', ['name' => 'Anonymous'])
        ->assertStatus(401);
});
