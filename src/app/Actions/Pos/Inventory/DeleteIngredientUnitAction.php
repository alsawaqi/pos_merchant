<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\IngredientAltUnit;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * v2 #13 — soft-delete an alternate unit. No FK references it (recipe lines
 * snapshot the LABEL string, not an id), so removing it never breaks history;
 * future entries simply can't pick it. A unit of the same name can be re-added
 * later (the create action restores the soft-deleted row).
 *
 * Audit event: inventory.ingredient_unit.deleted.
 */
final readonly class DeleteIngredientUnitAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(IngredientAltUnit $unit, User $actor): void
    {
        $companyId = $this->tenant->requiredId();

        DB::transaction(function () use ($unit, $actor, $companyId): void {
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient_unit.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: IngredientAltUnit::class,
                auditableId: $unit->id,
                oldValues: ['name' => $unit->name, 'factor' => (string) $unit->factor],
            ));

            $unit->delete();
        });
    }
}
