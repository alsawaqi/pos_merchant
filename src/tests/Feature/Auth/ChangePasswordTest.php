<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('changes the password and clears must_change_password', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->forceFill(['password' => 'old-password-123', 'must_change_password' => true])->save();

    $this->postJson('/auth/change-password', [
        'current_password' => 'old-password-123',
        'new_password' => 'new-password-456',
        'new_password_confirmation' => 'new-password-456',
    ])->assertOk()->assertJsonPath('must_change_password', false);

    $fresh = $ctx['user']->fresh();
    expect(Hash::check('new-password-456', (string) $fresh->password))->toBeTrue();
    expect((bool) $fresh->must_change_password)->toBeFalse();
});

it('rejects a wrong current password', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->forceFill(['password' => 'old-password-123'])->save();

    $this->postJson('/auth/change-password', [
        'current_password' => 'WRONG-PASSWORD',
        'new_password' => 'new-password-456',
        'new_password_confirmation' => 'new-password-456',
    ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
});

it('requires the new password to be confirmed and at least 8 chars', function (): void {
    makeMerchantActor();

    $this->postJson('/auth/change-password', [
        'current_password' => 'whatever',
        'new_password' => 'short',
        'new_password_confirmation' => 'mismatch',
    ])->assertStatus(422)->assertJsonValidationErrors(['new_password']);
});

it('surfaces must_change_password in the /auth/user payload', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->forceFill(['must_change_password' => true])->save();

    $this->getJson('/auth/user')->assertOk()->assertJsonPath('user.must_change_password', true);
});
