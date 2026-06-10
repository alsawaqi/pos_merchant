<?php

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Phase D7 — self-service forgot/reset password.
 *
 * The forgot endpoint must be enumeration-safe (200 for every
 * email), only ever mint tokens for ACTIVE MERCHANT rows, and the
 * reset endpoint must enforce single-use + expiry + the same
 * password rules as change-password.
 */

// ---------------------------------------------------------------
// POST /auth/forgot-password
// ---------------------------------------------------------------

it('returns 200, mints a hashed token, and emails the link for a real merchant user', function (): void {
    Mail::fake();

    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->postJson('/auth/forgot-password', ['email' => 'owner@example.com'])
        ->assertOk();

    $token = PasswordResetToken::query()->where('user_id', $user->id)->first();
    expect($token)->not->toBeNull();
    expect($token->used_at)->toBeNull();
    expect($token->expires_at->isFuture())->toBeTrue();
    // 64 hex chars = SHA-256 — the raw token is never stored.
    expect($token->token_hash)->toMatch('/^[0-9a-f]{64}$/');

    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($token): bool {
        // The emailed link carries the RAW token whose hash is stored.
        return $mail->hasTo('owner@example.com')
            && hash('sha256', $mail->resetToken) === $token->token_hash
            && str_contains($mail->resetUrl(), '/reset-password?token=');
    });

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.reset_link_sent',
        'auditable_id' => $user->id,
    ]);
});

it('returns 200 but mints nothing for an unknown email (anti-enumeration)', function (): void {
    Mail::fake();

    $this->postJson('/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk();

    expect(PasswordResetToken::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('returns 200 but mints nothing for a platform_admin email (cross-portal gate)', function (): void {
    Mail::fake();

    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'user_type' => 'platform_admin',
        'company_id' => null,
    ]);

    $this->postJson('/auth/forgot-password', ['email' => 'admin@example.com'])
        ->assertOk();

    expect(PasswordResetToken::query()->where('user_id', $admin->id)->count())->toBe(0);
    Mail::assertNothingSent();
});

it('returns 200 but mints nothing for a suspended merchant user', function (): void {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'suspended@example.com',
        'status' => 'suspended',
    ]);

    $this->postJson('/auth/forgot-password', ['email' => 'suspended@example.com'])
        ->assertOk();

    expect(PasswordResetToken::query()->where('user_id', $user->id)->count())->toBe(0);
    Mail::assertNothingSent();
});

it('replaces the outstanding token instead of stacking a second one', function (): void {
    Mail::fake();

    $user = User::factory()->create(['email' => 'owner@example.com']);

    // A stale outstanding token from an earlier request (outside
    // the 60s mint cooldown so the endpoint mints a fresh one).
    PasswordResetToken::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', str_repeat('a', 64)),
        'expires_at' => now()->addMinutes(30),
        'created_at' => now()->subMinutes(5),
    ]);

    $this->postJson('/auth/forgot-password', ['email' => 'owner@example.com'])
        ->assertOk();

    // Old token gone, exactly one fresh token alive.
    expect(PasswordResetToken::query()->where('user_id', $user->id)->count())->toBe(1);
    expect(
        PasswordResetToken::query()
            ->where('token_hash', hash('sha256', str_repeat('a', 64)))
            ->exists(),
    )->toBeFalse();
});

it('throttles the forgot endpoint after repeated attempts', function (): void {
    Mail::fake();

    $max = (int) config('pos_merchant_auth.rate_limits.password_reset_per_quarter_hour', 5);

    foreach (range(1, $max) as $i) {
        $this->postJson('/auth/forgot-password', ['email' => 'whoever@example.com'])
            ->assertOk();
    }

    $this->postJson('/auth/forgot-password', ['email' => 'whoever@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects a malformed email with 422', function (): void {
    $this->postJson('/auth/forgot-password', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

// ---------------------------------------------------------------
// POST /auth/reset-password
// ---------------------------------------------------------------

/**
 * End-to-end fixture: run the real forgot endpoint and capture the
 * raw token off the intercepted mailable.
 *
 * @return array{user: User, rawToken: string}
 */
function mintResetToken(string $email = 'owner@example.com'): array
{
    Mail::fake();

    $user = User::factory()->create([
        'email' => $email,
        'password' => 'original-password-123',
        'must_change_password' => true,
    ]);

    test()->postJson('/auth/forgot-password', ['email' => $email])->assertOk();

    $rawToken = '';
    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$rawToken): bool {
        $rawToken = $mail->resetToken;

        return true;
    });

    return ['user' => $user, 'rawToken' => $rawToken];
}

it('resets the password, clears must_change_password, and consumes the token', function (): void {
    ['user' => $user, 'rawToken' => $rawToken] = mintResetToken();

    $this->postJson('/auth/reset-password', [
        'email' => 'owner@example.com',
        'token' => $rawToken,
        'password' => 'brand-new-password-456',
        'password_confirmation' => 'brand-new-password-456',
    ])->assertOk();

    $fresh = $user->fresh();
    expect(Hash::check('brand-new-password-456', (string) $fresh->password))->toBeTrue();
    expect((bool) $fresh->must_change_password)->toBeFalse();

    // Token is stamped used — single-use.
    $token = PasswordResetToken::query()->where('user_id', $user->id)->first();
    expect($token->used_at)->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.password_reset_completed',
        'actor_user_id' => $user->id,
        'auditable_id' => $user->id,
    ]);
});

it('rejects a wrong token with a generic error', function (): void {
    ['user' => $user] = mintResetToken();

    $this->postJson('/auth/reset-password', [
        'email' => 'owner@example.com',
        'token' => str_repeat('x', 64),
        'password' => 'brand-new-password-456',
        'password_confirmation' => 'brand-new-password-456',
    ])->assertStatus(422)->assertJsonValidationErrors(['token']);

    expect(Hash::check('original-password-123', (string) $user->fresh()->password))->toBeTrue();
});

it('rejects an expired token', function (): void {
    $user = User::factory()->create([
        'email' => 'owner@example.com',
        'password' => 'original-password-123',
    ]);

    $rawToken = str_repeat('b', 64);
    PasswordResetToken::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->subMinute(),
        'created_at' => now()->subHours(2),
    ]);

    $this->postJson('/auth/reset-password', [
        'email' => 'owner@example.com',
        'token' => $rawToken,
        'password' => 'brand-new-password-456',
        'password_confirmation' => 'brand-new-password-456',
    ])->assertStatus(422)->assertJsonValidationErrors(['token']);

    expect(Hash::check('original-password-123', (string) $user->fresh()->password))->toBeTrue();
});

it('refuses to reuse a consumed token', function (): void {
    ['rawToken' => $rawToken] = mintResetToken();

    $payload = [
        'email' => 'owner@example.com',
        'token' => $rawToken,
        'password' => 'brand-new-password-456',
        'password_confirmation' => 'brand-new-password-456',
    ];

    $this->postJson('/auth/reset-password', $payload)->assertOk();

    $this->postJson('/auth/reset-password', [
        ...$payload,
        'password' => 'yet-another-password-789',
        'password_confirmation' => 'yet-another-password-789',
    ])->assertStatus(422)->assertJsonValidationErrors(['token']);
});

it('cannot reset a platform_admin account even with a valid token row', function (): void {
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'user_type' => 'platform_admin',
        'company_id' => null,
        'password' => 'admin-password-123',
    ]);

    // Simulate an attacker who somehow planted a token row for the
    // admin — the merchant()-scoped lookup must still refuse it.
    $rawToken = str_repeat('c', 64);
    PasswordResetToken::query()->create([
        'user_id' => $admin->id,
        'token_hash' => hash('sha256', $rawToken),
        'expires_at' => now()->addHour(),
        'created_at' => now(),
    ]);

    $this->postJson('/auth/reset-password', [
        'email' => 'admin@example.com',
        'token' => $rawToken,
        'password' => 'hijacked-password-456',
        'password_confirmation' => 'hijacked-password-456',
    ])->assertStatus(422)->assertJsonValidationErrors(['token']);

    expect(Hash::check('admin-password-123', (string) $admin->fresh()->password))->toBeTrue();
});

it('enforces the change-password rules on the new password', function (): void {
    ['rawToken' => $rawToken] = mintResetToken();

    // Too short.
    $this->postJson('/auth/reset-password', [
        'email' => 'owner@example.com',
        'token' => $rawToken,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);

    // Confirmation mismatch.
    $this->postJson('/auth/reset-password', [
        'email' => 'owner@example.com',
        'token' => $rawToken,
        'password' => 'brand-new-password-456',
        'password_confirmation' => 'something-else-entirely',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});
