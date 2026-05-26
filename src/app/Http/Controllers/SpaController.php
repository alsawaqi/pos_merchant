<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Single-page shell controller. Both /login and the authenticated
 * /{anything} routes resolve here; the blade view boots the Vue
 * SPA which then takes over client-side routing.
 *
 * The window.__INITIAL_AUTH__ payload injected by the blade lets
 * the SPA paint the correct shell on the very first frame instead
 * of flashing a guest layout while it asks /auth/user "who am I?".
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = Auth::guard('web')->user();

        return view('app', [
            'initialAuth' => [
                'authenticated' => $user !== null,
                'user' => $user === null ? null : [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'user_type' => $user->user_type,
                    'status' => $user->status,
                    'company_id' => $user->company_id,
                    'locale' => $user->locale,
                ],
            ],
        ]);
    }
}
