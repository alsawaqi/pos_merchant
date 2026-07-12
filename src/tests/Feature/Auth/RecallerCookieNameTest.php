<?php

declare(strict_types=1);

use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

/**
 * Locks the Phase 3 guarantee: the merchant portal uses a per-app remember-me
 * cookie name. pos_admin and pos_merchant share one APP_KEY + the pos_users
 * table, so a shared recaller cookie name would let one app's "remember me"
 * cookie auto-authenticate into the other if SESSION_DOMAIN were ever widened.
 * If the custom guard driver ever regresses to the stock 'session' driver, the
 * name reverts to remember_web_<sha1(...)> and this test fails.
 */
it('uses a distinct per-app remember-me cookie name', function (): void {
    $guard = Auth::guard('web');

    expect($guard)->toBeInstanceOf(SessionGuard::class)
        ->and($guard->getRecallerName())->toBe('remember_pos_merchant_web');
});
