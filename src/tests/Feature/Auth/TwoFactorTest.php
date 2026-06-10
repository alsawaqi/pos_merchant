<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/**
 * Phase D8 — opt-in TOTP two-factor auth.
 *
 * Enrolment: start → confirm-with-live-code → enabled + one-time
 * recovery codes; secrets/codes encrypted at rest. Login: password
 * alone must NOT authenticate an enrolled user — the challenge
 * endpoint (TOTP or single-use recovery code, throttled) completes
 * the session exactly like the normal flow.
 */

/** Current 6-digit TOTP for a base32 secret, via the same RFC 6238 engine. */
function currentTotp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/**
 * Walk a signed-in actor through the full enrolment so login tests
 * start from an ENABLED account.
 *
 * @return array{user: User, secret: string, recoveryCodes: list<string>}
 */
function enrollTwoFactor(User $user): array
{
    $start = test()->postJson('/auth/two-factor')->assertOk();
    $secret = (string) $start->json('secret');

    $confirm = test()->postJson('/auth/two-factor/confirm', [
        'code' => currentTotp($secret),
    ])->assertOk();

    return [
        'user' => $user->fresh(),
        'secret' => $secret,
        'recoveryCodes' => $confirm->json('recovery_codes'),
    ];
}

// ---------------------------------------------------------------
// Enrolment
// ---------------------------------------------------------------

it('starts enrolment with a QR + secret and stores the secret encrypted', function (): void {
    ['user' => $user] = makeMerchantActor();

    $response = $this->postJson('/auth/two-factor')->assertOk();

    $secret = (string) $response->json('secret');
    expect($secret)->toMatch('/^[A-Z2-7]{32}$/'); // base32, 160 bits
    expect((string) $response->json('otpauth_url'))
        ->toContain('otpauth://totp/')
        ->toContain('secret='.$secret);
    expect((string) $response->json('svg'))->toStartWith('<svg');

    // Encrypted at rest: the raw column is ciphertext, not the secret.
    $raw = (string) DB::table('pos_users')->where('id', $user->id)->value('two_factor_secret');
    expect($raw)->not->toBe($secret);
    expect(Crypt::decryptString($raw))->toBe($secret);

    // Unconfirmed — login is NOT yet gated.
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.two_factor_setup_started',
        'actor_user_id' => $user->id,
    ]);
});

it('enables 2FA only after a valid code and hands out hashed-at-rest recovery codes', function (): void {
    ['user' => $user] = makeMerchantActor();

    $secret = (string) $this->postJson('/auth/two-factor')->assertOk()->json('secret');

    // Wrong code first — still disabled.
    $wrong = currentTotp($secret) === '000000' ? '000001' : '000000';
    $this->postJson('/auth/two-factor/confirm', ['code' => $wrong])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    // Valid code — enabled + 8 one-time recovery codes.
    $response = $this->postJson('/auth/two-factor/confirm', ['code' => currentTotp($secret)])
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', true);

    $codes = $response->json('recovery_codes');
    expect($codes)->toHaveCount(8);
    expect($codes[0])->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/');

    $fresh = $user->fresh();
    expect($fresh->two_factor_confirmed_at)->not->toBeNull();

    // Stored values are SHA-256 hashes of the issued codes — never plaintext.
    $stored = $fresh->two_factor_recovery_codes;
    expect($stored)->toHaveCount(8);
    foreach ($codes as $i => $plain) {
        $canonical = strtoupper(str_replace('-', '', $plain));
        expect($stored[$i])->toBe(hash('sha256', $canonical));
    }

    // And the raw column itself is ciphertext.
    $raw = (string) DB::table('pos_users')->where('id', $user->id)->value('two_factor_recovery_codes');
    expect($raw)->not->toContain($codes[0]);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.two_factor_enabled',
        'actor_user_id' => $user->id,
    ]);
});

it('refuses to start a new enrolment while 2FA is already enabled', function (): void {
    ['user' => $user] = makeMerchantActor();
    enrollTwoFactor($user);

    $this->postJson('/auth/two-factor')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['two_factor']);
});

it('refuses to confirm before a setup was started', function (): void {
    makeMerchantActor();

    $this->postJson('/auth/two-factor/confirm', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['two_factor']);
});

it('requires authentication for every enrolment endpoint', function (): void {
    $this->postJson('/auth/two-factor')->assertStatus(401);
    $this->postJson('/auth/two-factor/confirm', ['code' => '123456'])->assertStatus(401);
    $this->deleteJson('/auth/two-factor', ['current_password' => 'x', 'code' => '123456'])->assertStatus(401);
});

// ---------------------------------------------------------------
// Login challenge
// ---------------------------------------------------------------

/**
 * Enrol a merchant user, then sign out so the login flow can be
 * exercised from a clean guest session.
 *
 * @return array{user: User, secret: string, recoveryCodes: list<string>}
 */
function enrolledGuest(): array
{
    ['user' => $user] = makeMerchantActor();
    $enrolled = enrollTwoFactor($user);

    test()->postJson('/auth/logout')->assertNoContent();

    return $enrolled;
}

it('does not authenticate an enrolled user on password alone — it issues a challenge', function (): void {
    ['user' => $user] = enrolledGuest();

    $this->postJson('/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertExactJson(['two_factor' => true]);

    $this->assertGuest('web');

    // No session, no API access.
    $this->getJson('/auth/user')->assertStatus(401);
});

it('redirects a form (non-JSON) login to the challenge page', function (): void {
    ['user' => $user] = enrolledGuest();

    $this->post('/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/two-factor-challenge');

    $this->assertGuest('web');
});

it('completes the login with a valid TOTP code', function (): void {
    ['user' => $user, 'secret' => $secret] = enrolledGuest();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->postJson('/auth/two-factor-challenge', ['code' => currentTotp($secret)])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.two_factor_enabled', true);

    $this->assertAuthenticatedAs($user, 'web');
    expect($user->fresh()->last_login_at)->not->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.two_factor_challenge_passed',
        'actor_user_id' => $user->id,
    ]);
});

it('rejects an invalid challenge code, audits it, and stays guest', function (): void {
    ['user' => $user, 'secret' => $secret] = enrolledGuest();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $wrong = currentTotp($secret) === '000000' ? '000001' : '000000';
    $this->postJson('/auth/two-factor-challenge', ['code' => $wrong])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    $this->assertGuest('web');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.two_factor_challenge_failed',
        'actor_user_id' => $user->id,
    ]);
});

it('throttles repeated challenge failures', function (): void {
    ['user' => $user, 'secret' => $secret] = enrolledGuest();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $max = (int) config('pos_merchant_auth.rate_limits.two_factor_per_minute', 5);
    $wrong = currentTotp($secret) === '000000' ? '000001' : '000000';

    foreach (range(1, $max) as $i) {
        $this->postJson('/auth/two-factor-challenge', ['code' => $wrong])
            ->assertStatus(422);
    }

    $response = $this->postJson('/auth/two-factor-challenge', ['code' => $wrong])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect((string) $response->json('errors.code.0'))->toContain('seconds');
    $this->assertGuest('web');
});

it('accepts a recovery code exactly once', function (): void {
    ['user' => $user, 'recoveryCodes' => $codes] = enrolledGuest();

    $burned = $codes[0];

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->postJson('/auth/two-factor-challenge', ['recovery_code' => $burned])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
    $this->assertAuthenticatedAs($user, 'web');

    // Burned — 7 hashes remain.
    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(7);

    // Second use of the SAME code from a fresh login fails.
    $this->postJson('/auth/logout')->assertNoContent();
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->postJson('/auth/two-factor-challenge', ['recovery_code' => $burned])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
    $this->assertGuest('web');
});

it('rejects a challenge without any pending login state', function (): void {
    enrolledGuest();

    $this->postJson('/auth/two-factor-challenge', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['challenge']);

    $this->assertGuest('web');
});

it('expires the pending challenge after its TTL', function (): void {
    ['user' => $user, 'secret' => $secret] = enrolledGuest();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $ttl = (int) config('pos_merchant_auth.two_factor.challenge_ttl_minutes', 5);
    $this->travel($ttl + 1)->minutes();

    $this->postJson('/auth/two-factor-challenge', ['code' => currentTotp($secret)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['challenge']);

    $this->assertGuest('web');
});

it('reports challenge-pending state to the SPA page', function (): void {
    ['user' => $user] = enrolledGuest();

    $this->getJson('/auth/two-factor-challenge')
        ->assertOk()
        ->assertJsonPath('pending', false);

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->getJson('/auth/two-factor-challenge')
        ->assertOk()
        ->assertJsonPath('pending', true);
});

it('preserves must_change_password through the challenge completion', function (): void {
    ['user' => $user, 'secret' => $secret] = enrolledGuest();
    $user->forceFill(['must_change_password' => true])->save();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->postJson('/auth/two-factor-challenge', ['code' => currentTotp($secret)])
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true);
});

it('still logs a non-enrolled user straight in (no challenge)', function (): void {
    ['user' => $user] = makeMerchantActor();
    $this->postJson('/auth/logout')->assertNoContent();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.two_factor_enabled', false);

    $this->assertAuthenticatedAs($user, 'web');
});

// ---------------------------------------------------------------
// Disable
// ---------------------------------------------------------------

it('disables 2FA with password + live code, returning login to a single step', function (): void {
    ['user' => $user] = makeMerchantActor();
    ['user' => $user, 'secret' => $secret] = enrollTwoFactor($user);

    $this->deleteJson('/auth/two-factor', [
        'current_password' => 'password',
        'code' => currentTotp($secret),
    ])
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', false);

    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull();
    expect($fresh->two_factor_recovery_codes)->toBeNull();
    expect($fresh->two_factor_confirmed_at)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.two_factor_disabled',
        'actor_user_id' => $user->id,
    ]);

    // Plain login works again — no challenge step.
    $this->postJson('/auth/logout')->assertNoContent();
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
    $this->assertAuthenticatedAs($user, 'web');
});

it('refuses to disable 2FA with a wrong password even when the code is valid', function (): void {
    ['user' => $user] = makeMerchantActor();
    ['secret' => $secret] = enrollTwoFactor($user);

    $this->deleteJson('/auth/two-factor', [
        'current_password' => 'not-the-password',
        'code' => currentTotp($secret),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('refuses to disable 2FA with a wrong code even when the password is valid', function (): void {
    ['user' => $user] = makeMerchantActor();
    ['secret' => $secret] = enrollTwoFactor($user);

    $wrong = currentTotp($secret) === '000000' ? '000001' : '000000';
    $this->deleteJson('/auth/two-factor', [
        'current_password' => 'password',
        'code' => $wrong,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('accepts a recovery code as the second factor when disabling', function (): void {
    ['user' => $user] = makeMerchantActor();
    ['recoveryCodes' => $codes] = enrollTwoFactor($user);

    $this->deleteJson('/auth/two-factor', [
        'current_password' => 'password',
        'recovery_code' => $codes[3],
    ])
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', false);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});
