<?php

declare(strict_types=1);

namespace App\Actions\Pos\FloorPlan;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\FloorStatus;
use App\Models\Branch;
use App\Models\Floor;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Create a floor under a branch the actor's company owns.
 *
 * Refuses if the target branch belongs to a different
 * merchant. Validator on the controller side checks the
 * (branch_id, name) uniqueness for a cleaner 422 — this
 * action's job is just the atomic write + audit.
 *
 * Audit event: floor.created.
 */
final readonly class CreateFloorAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array{name: string, name_ar?: string|null, display_order?: int}  $attributes
     */
    public function handle(Branch $branch, array $attributes, User $actor): Floor
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $branch->company_id !== $companyId) {
            // Defence in depth — the controller's tenant guard
            // should have already 404'd before we get here.
            abort(404);
        }

        return DB::transaction(function () use ($branch, $attributes, $actor, $companyId): Floor {
            /** @var Floor $floor */
            $floor = Floor::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'name' => $attributes['name'],
                'name_ar' => $attributes['name_ar'] ?? null,
                'display_order' => $attributes['display_order'] ?? 0,
                'status' => FloorStatus::Active->value,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'floor.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: Floor::class,
                auditableId: $floor->id,
                newValues: [
                    'name' => $floor->name,
                    'name_ar' => $floor->name_ar,
                    'display_order' => $floor->display_order,
                ],
            ));

            return $floor;
        });
    }
}
