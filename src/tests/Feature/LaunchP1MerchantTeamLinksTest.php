<?php

declare(strict_types=1);

/*
 * Owner follow-up 2026-10-01 (merchant portal):
 *  4. the owner creating or resetting a teammate's login uses the same
 *     set-password link flow as pos_admin — copy dialog + email when
 *     configured — and no password is generated or shown anywhere;
 *  3. a reset blocks the old password at once, ends the sessions, and a
 *     user mid-reset is told to use the link.
 */

use App\Enums\MerchantRole;
use App\Mail\SetPasswordLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://posmerchant.mithqal.test']);
});

/**
 * @return array{token: string, email: string, path: string}
 */
function p1TeamLink(string $url): array
{
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    return [
        'token' => (string) ($query['token'] ?? ''),
        'email' => (string) ($query['email'] ?? ''),
        'path' => (string) ($parts['path'] ?? ''),
    ];
}

it('creates a teammate login with a 72-hour set-password link and no password', function (): void {
    $ctx = makeMerchantActor();

    $response = $this->postJson('/api/portal-users', [
        'name' => 'Waiter One',
        'email' => 'waiter.one@cafe.test',
        'role' => MerchantRole::Manager->value,
    ])->assertCreated()
        ->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'invite')
        ->assertJsonPath('data.setup_pending', true);

    $link = p1TeamLink((string) $response->json('set_password_link.url'));
    expect($link['path'])->toBe('/setup-password')
        ->and($link['email'])->toBe('waiter.one@cafe.test')
        ->and(strlen($link['token']))->toBe(64);

    $user = User::query()->where('email', 'waiter.one@cafe.test')->firstOrFail();
    expect($user->password)->toBeNull()
        ->and((bool) $user->must_change_password)->toBeFalse();

    $row = DB::table('pos_password_reset_tokens')->where('user_id', $user->id)->sole();
    expect($row->token_hash)->toBe(hash('sha256', $link['token']))
        ->and($row->purpose)->toBe('invite')
        ->and((int) $row->issued_by_user_id)->toBe($ctx['user']->id);
    expect(now()->diffInMinutes(Carbon::parse($row->expires_at)))->toBeGreaterThan(71 * 60);

    $audit = DB::table('pos_audit_logs')->where('event', 'portal_user.set_password_link_issued')->sole();
    expect((string) $audit->new_values)->not->toContain($link['token']);
});

it('emails the teammate link when mail is configured, and never into the log', function (): void {
    makeMerchantActor();
    Mail::fake();

    config(['mail.default' => 'log']);
    $this->postJson('/api/portal-users', [
        'name' => 'Log Only', 'email' => 'log.only@cafe.test', 'role' => MerchantRole::Manager->value,
    ])->assertCreated()->assertJsonPath('set_password_link.emailed', false);
    Mail::assertNothingSent();

    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.mithqal.test']);
    $response = $this->postJson('/api/portal-users', [
        'name' => 'Mailed', 'email' => 'mailed@cafe.test', 'role' => MerchantRole::Manager->value,
    ])->assertCreated()->assertJsonPath('set_password_link.emailed', true);

    $url = (string) $response->json('set_password_link.url');
    Mail::assertSent(SetPasswordLinkMail::class, fn (SetPasswordLinkMail $mail): bool => $mail->hasTo('mailed@cafe.test')
        && $mail->url === $url && $mail->purpose === 'invite');
});

it('still creates the teammate and returns the link when the mail server is down', function (): void {
    makeMerchantActor();
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 9,
        'mail.mailers.smtp.timeout' => 2,
    ]);

    $this->postJson('/api/portal-users', [
        'name' => 'Offline', 'email' => 'offline@cafe.test', 'role' => MerchantRole::Manager->value,
    ])->assertCreated()
        ->assertJsonPath('set_password_link.emailed', false)
        ->assertJson(fn ($json) => $json->where('set_password_link.email_error', fn ($v) => is_string($v) && $v !== '')->etc());
});

it('lets the teammate set a password with the link and sign in', function (): void {
    makeMerchantActor();
    $link = p1TeamLink((string) $this->postJson('/api/portal-users', [
        'name' => 'New Cook', 'email' => 'cook@cafe.test', 'role' => MerchantRole::Manager->value,
    ])->assertCreated()->json('set_password_link.url'));

    $this->postJson('/auth/logout');
    $this->app['auth']->forgetGuards();

    $this->postJson('/auth/reset-password', [
        'email' => $link['email'],
        'token' => $link['token'],
        'password' => 'Cook-password-77',
        'password_confirmation' => 'Cook-password-77',
    ])->assertOk();

    $this->postJson('/auth/login', ['email' => 'cook@cafe.test', 'password' => 'Cook-password-77'])->assertOk();
});

it('blocks a teammate\'s old password at once on reset and tells them to use the link', function (): void {
    $ctx = makeMerchantActor();
    $teammate = User::factory()->create([
        'company_id' => $ctx['company']->id,
        'user_type' => 'merchant',
        'status' => 'active',
        'email' => 'cashier@cafe.test',
        'password' => 'Cashier-old-pass-1',
    ]);
    $versionBefore = (int) DB::table('pos_users')->where('id', $teammate->id)->value('auth_version');

    $response = $this->postJson("/api/portal-users/{$teammate->id}/reset-password")
        ->assertOk()
        ->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'reset');
    $link = p1TeamLink((string) $response->json('set_password_link.url'));
    expect($link['path'])->toBe('/reset-password');

    $fresh = DB::table('pos_users')->where('id', $teammate->id)->first();
    expect($fresh->password)->toBeNull()
        ->and((int) $fresh->auth_version)->not->toBe($versionBefore);

    // Resending to a teammate mid-reset keeps it a 60-minute reset link.
    $this->postJson("/api/portal-users/{$teammate->id}/reset-password")
        ->assertOk()->assertJsonPath('set_password_link.purpose', 'reset');

    // The teammate, elsewhere: the old password no longer works and the
    // message points to the link.
    $this->postJson('/auth/logout');
    $this->app['auth']->forgetGuards();
    $message = (string) $this->postJson('/auth/login', ['email' => 'cashier@cafe.test', 'password' => 'Cashier-old-pass-1'])
        ->assertUnprocessable()
        ->json('errors.email.0');
    expect($message)->toContain('set-password link');
    $this->assertGuest();
});

it('keeps the team page free of generated passwords', function (): void {
    $page = (string) file_get_contents(resource_path('js/Pages/Merchant/PortalUsers/Index.vue'));
    $client = (string) file_get_contents(resource_path('js/lib/api/portalUsers.ts'));

    expect($page)->toContain('<SetPasswordLinkDialog')->not->toContain('plaintext_password');
    expect($client)->toContain('set_password_link')->not->toContain('plaintext_password: string');
    expect((string) file_get_contents(resource_path('js/Components/SetPasswordLinkDialog.vue')))
        ->toContain('data-testid="copy-set-password-link"');
});
