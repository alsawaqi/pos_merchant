<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\AddOnSelectionMode;
use App\Models\AddOnGroup;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.9 — create an add-on group for the actor's company.
 *
 * Validator on the controller enforces (company_id, name)
 * uniqueness; this Action just does the atomic write + audit.
 *
 * Audit event: catalogue.addon_group.created.
 */
final readonly class CreateAddOnGroupAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, name_ar?: string|null, selection_mode?: string, is_global?: bool, display_order?: int}  $attributes
     */
    public function handle(array $attributes, User $actor): AddOnGroup
    {
        $companyId = $this->tenant->requiredId();

        return DB::transaction(function () use ($attributes, $actor, $companyId): AddOnGroup {
            /** @var AddOnGroup $group */
            $group = AddOnGroup::query()->create([
                'company_id' => $companyId,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'selection_mode' => $attributes['selection_mode'] ?? AddOnSelectionMode::Single->value,
                'is_global' => $attributes['is_global'] ?? false,
                'display_order' => $attributes['display_order'] ?? 0,
                'status' => 'active',
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.addon_group.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: AddOnGroup::class,
                auditableId: $group->id,
                newValues: [
                    'name' => $group->name,
                    'selection_mode' => $group->selection_mode->value,
                    'is_global' => $group->is_global,
                ],
            ));

            return $group;
        });
    }
}
