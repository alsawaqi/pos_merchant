<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint the SPA hits when a previous request 419'd on a stale
 * CSRF token. Returns the current token + sets a fresh XSRF-TOKEN
 * cookie. The api.ts wrapper auto-calls this on 419 and retries
 * the original request exactly once — same shape as pos_admin.
 */
class CsrfTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'token' => $request->session()->token(),
        ]);
    }
}
