<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 P1-3 — merchant portal mail works by configuration only:
 * documented SMTP settings (no committed password), MAIL_ENCRYPTION
 * honoured, and a dead mail server never breaks forgot-password.
 */

use App\Models\PasswordResetToken;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('documents SMTP settings in the production env template without a password', function (): void {
    $env = Dotenv::parse((string) file_get_contents(base_path('.env.production.example')));

    expect($env['MAIL_MAILER'] ?? null)->toBe('smtp')
        ->and($env)->toHaveKeys(['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION'])
        ->and($env['MAIL_PASSWORD'])->toBe('')
        ->and($env['MAIL_FROM_ADDRESS'] ?? null)->toBe('noreply@mithqal.net')
        ->and($env['MAIL_FROM_NAME'] ?? null)->toBe('MITHQAL');
});

it('honours MAIL_ENCRYPTION=ssl as implicit TLS and bounds the SMTP wait', function (): void {
    $saved = [$_ENV['MAIL_ENCRYPTION'] ?? null, $_SERVER['MAIL_ENCRYPTION'] ?? null];
    $_ENV['MAIL_ENCRYPTION'] = $_SERVER['MAIL_ENCRYPTION'] = 'ssl';

    try {
        $config = require config_path('mail.php');
    } finally {
        [$env, $server] = $saved;
        if ($env === null) {
            unset($_ENV['MAIL_ENCRYPTION']);
        } else {
            $_ENV['MAIL_ENCRYPTION'] = $env;
        }
        if ($server === null) {
            unset($_SERVER['MAIL_ENCRYPTION']);
        } else {
            $_SERVER['MAIL_ENCRYPTION'] = $server;
        }
    }

    expect($config['mailers']['smtp']['scheme'])->toBe('smtps')
        ->and($config['mailers']['smtp']['timeout'])->toBeInt()->toBeGreaterThan(0);
});

it('never sends or logs a forgot-password link when mail is only logged or has no host', function (array $mail): void {
    config($mail);
    Mail::fake();
    $user = User::factory()->create(['email' => 'owner@cafe.test']);

    $this->postJson('/auth/forgot-password', ['email' => 'owner@cafe.test'])->assertOk();

    Mail::assertNothingSent();
    expect(PasswordResetToken::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with([
    'MAIL_MAILER=log' => [['mail.default' => 'log']],
    'SMTP without a host' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']],
]);

it('does not let a forgot-password request revoke an admin-issued reset link', function (): void {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.mithqal.test']);
    Mail::fake();
    // An admin reset: the old password is gone and a reset link is out.
    $user = User::factory()->create(['email' => 'reset.me@cafe.test', 'password' => null]);
    $raw = str_repeat('a', 64);
    DB::table('pos_password_reset_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $raw),
        'purpose' => 'reset',
        'expires_at' => now()->addHour(),
        'created_at' => now(),
    ]);

    // Anyone can type this email on the forgot-password page.
    $this->postJson('/auth/forgot-password', ['email' => 'reset.me@cafe.test'])->assertOk();

    expect(DB::table('pos_password_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->pluck('purpose')->sort()->values()->all())
        ->toBe(['forgot', 'reset']);

    // The admin's link still works.
    $this->postJson('/auth/reset-password', [
        'email' => 'reset.me@cafe.test',
        'token' => $raw,
        'password' => 'Admin-link-pass-1',
        'password_confirmation' => 'Admin-link-pass-1',
    ])->assertOk();
});

it('answers forgot-password normally when the mail server is down', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 9,
        'mail.mailers.smtp.timeout' => 2,
    ]);
    $user = User::factory()->create(['email' => 'owner@cafe.test']);

    $this->postJson('/auth/forgot-password', ['email' => 'owner@cafe.test'])->assertOk();

    // The link still exists, so a resend or an admin link can follow.
    expect(PasswordResetToken::query()->where('user_id', $user->id)->exists())->toBeTrue();
});
