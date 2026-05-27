<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/**
 * Lane A1 — Sanctum config for pos_merchant.
 *
 * pos_merchant uses Sanctum exclusively for BEARER-TOKEN auth
 * (the Android cashier app), not for SPA cookie auth. The
 * portal SPA at /merchant uses session cookies via the standard
 * web guard — those routes never touch Sanctum.
 *
 * `stateful` empty: the Android device's domain is not a
 *   first-party SPA domain. We want pure Bearer behaviour —
 *   no CSRF dance, no cookie issuance.
 * `guard => []`: do not fall back to any session guard. A
 *   request to an auth:sanctum endpoint must present a valid
 *   Bearer token or be rejected outright.
 * `expiration => null`: lives forever (revocable per-token).
 *   PIN-session tokens carry their own short expires_at.
 * `token_prefix => 'mt_'`: makes leaked tokens easy to grep
 *   and lets GitHub secret-scanning recognise them.
 */
return [

    'stateful' => [],

    'guard' => [],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'mt_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
