<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\MerchantTenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * For every authenticated request, pins the merchant tenant
 * scope to the signed-in user's company_id AND switches spatie's
 * permission team_id to the same value.
 *
 * Without the spatie team-id switch, a user's roles would be
 * queried under the default team scope (often team_id=0, the
 * pos_admin platform team) and would always come up empty for
 * merchant users — every permission check would fail.
 *
 * Wired in bootstrap/app.php's $middleware->web() append after
 * the auth gate, so by the time we run $request->user() is
 * guaranteed populated for routes that pass through this
 * middleware. For guest routes (login page itself), there's
 * nothing to set — we short-circuit.
 */
class SetMerchantTenantContext
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->company_id !== null) {
            $this->tenant->set((int) $user->company_id);
            app(PermissionRegistrar::class)->setPermissionsTeamId((int) $user->company_id);
        }

        return $next($request);
    }
}
