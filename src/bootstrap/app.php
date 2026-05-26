<?php

declare(strict_types=1);

use App\Http\Middleware\PreventBackHistoryCache;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Append to EVERY response globally — covers SPA HTML, JSON
        // API, file downloads, the lot. SecurityHeaders sets CSP +
        // anti-clickjacking + COOP/CORP. PreventBackHistoryCache
        // sets no-store / Vary: Cookie so a back-button after
        // logout can never restore the merchant shell.
        $middleware->append(SecurityHeaders::class);
        $middleware->append(PreventBackHistoryCache::class);

        // The merchant `web` guard redirects guests to /login —
        // matches the route name registered in routes/web.php.
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
