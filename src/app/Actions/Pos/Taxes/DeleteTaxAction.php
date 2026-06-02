<?php

declare(strict_types=1);

namespace App\Actions\Pos\Taxes;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Tax;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete a company tax. The POS stops applying it on the next config
 * fetch; the row stays referenceable for historical orders. Audit event:
 * settings.tax.deleted.
 */
final readonly class DeleteTaxAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Tax $tax, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $tax->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($tax, $actor, $companyId): void {
            $taxId = $tax->id;
            $snapshot = [
                'name' => $tax->name,
                'rate_percent' => (string) $tax->rate_percent,
                'is_active' => (bool) $tax->is_active,
            ];

            $tax->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'settings.tax.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Tax::class,
                auditableId: $taxId,
                oldValues: $snapshot,
            ));
        });
    }
}
