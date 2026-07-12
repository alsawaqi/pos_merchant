<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\MerchantTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global tenant scope: constrains every query on a company-owned model to the
 * signed-in merchant's company_id, so isolation is enforced by the ORM rather
 * than by a hand-written guard in each controller.
 *
 * Resolves the tenant from the request-scoped {@see MerchantTenantContext}.
 * When NO tenant is pinned the scope is a deliberate NO-OP — it never injects
 * `company_id = null`. The unpinned cases are all legitimate:
 *   - guests + the auth flows (login / 2FA / password-reset) resolve the User
 *     by email BEFORE the company is known (and User is not company-scoped);
 *   - route-model binding runs before SetMerchantTenantContext pins the tenant;
 *   - console commands + queued jobs have no request tenant.
 * Fail-closing there would 404 every detail route and break login; injecting
 * `company_id = null` would silently hide every row. So: no tenant → no filter.
 *
 * Enforcement therefore applies to every query issued AFTER the tenant is
 * pinned — i.e. inside controllers, actions, reports and relationship loads on
 * an authenticated request. (Route-model binding stays covered by the existing
 * refuseIfNotInTenant() guards until Phase 2b moves the pin ahead of binding.)
 *
 * Escape hatch for legitimate cross-tenant tooling (admin backfills, platform
 * reports): Model::withoutGlobalScope(BelongsToCompanyScope::class).
 */
final class BelongsToCompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(MerchantTenantContext::class)->id();

        if ($companyId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
