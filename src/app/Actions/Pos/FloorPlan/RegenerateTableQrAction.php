<?php

declare(strict_types=1);

namespace App\Actions\Pos\FloorPlan;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Table;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Roll the table's scan-to-order token. Use case: the
 * printed QR card was stolen / photographed by a competitor
 * / accidentally screenshotted and shared. Generating a new
 * token invalidates every prior scan and forces the merchant
 * to reprint the card.
 *
 * Audit event: table.qr_regenerated. Like reset-password
 * and reset-PIN flows, the new token is RETURNED in the
 * response envelope but NEVER written to the audit log
 * (treat as bearer credential).
 */
final readonly class RegenerateTableQrAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array{table: Table, qr_token: string}
     */
    public function handle(Table $table, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $table->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($table, $actor, $companyId): array {
            $newToken = Table::mintQrToken();
            $table->qr_token = $newToken;
            $table->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'table.qr_regenerated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $table->floor->branch_id,
                auditableType: Table::class,
                auditableId: $table->id,
                // Intentionally empty — the event row itself is
                // the audit signal; the new token's value never
                // touches disk outside the bcrypt-stored row.
                newValues: [],
            ));

            return [
                'table' => $table,
                'qr_token' => $newToken,
            ];
        });
    }
}
