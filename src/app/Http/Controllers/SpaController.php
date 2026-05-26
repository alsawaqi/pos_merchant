<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single-page shell controller. Both /login and the authenticated
 * /{anything} routes resolve here; the blade view boots the Vue
 * SPA which then takes over client-side routing.
 *
 * The window.__INITIAL_AUTH__ payload injected by the blade lets
 * the SPA paint the correct shell on the very first frame instead
 * of flashing a guest layout while it asks /auth/user "who am I?".
 *
 * Includes roles + permissions under the user's company team
 * scope so the SPA's can() / hasRole() helpers can mirror
 * server-side gates without an extra round-trip.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = Auth::guard('web')->user();

        return view('app', [
            'initialAuth' => [
                'authenticated' => $user !== null,
                'user' => $user === null ? null : $this->userPayload($user),
            ],
        ]);
    }

    /**
     * Same shape as AuthenticatedSessionController::userPayload —
     * keep both in sync.
     *
     * @return array{id: int|string|null, name: string|null, email: string|null, user_type: string|null, status: string|null, company_id: int|null, locale: string|null, roles: list<string>, permissions: list<string>}
     */
    private function userPayload(User $user): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        if ($user->company_id !== null) {
            $registrar->setPermissionsTeamId((int) $user->company_id);
        }

        try {
            $roles = $user->getRoleNames()->all();
            $permissions = $user->getAllPermissions()->pluck('name')->all();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'status' => $user->status,
            'company_id' => $user->company_id,
            'locale' => $user->locale,
            'roles' => array_values($roles),
            'permissions' => array_values($permissions),
        ];
    }
}
