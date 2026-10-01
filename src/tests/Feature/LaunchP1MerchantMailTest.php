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
