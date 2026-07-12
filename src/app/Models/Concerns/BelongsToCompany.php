<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\BelongsToCompanyScope;
use App\Support\MerchantTenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as company-owned: applies {@see BelongsToCompanyScope} so every
 * query is automatically constrained to the current merchant's company_id, and
 * stamps company_id on create.
 *
 * The create stamp is ADDITIVE-ONLY: it fills company_id from the pinned tenant
 * only when the caller left it empty AND a tenant is set. It never overwrites an
 * explicit value (audit writes and parent-derived creates pass their own
 * company_id) and never throws when unpinned (console / seed / queue flows).
 * Enforcement value lives on the read side; this stamp is just a backstop so a
 * future create path that forgets to set company_id can't write an orphan row.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new BelongsToCompanyScope());

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId !== null) {
                $model->setAttribute('company_id', $companyId);
            }
        });
    }
}
