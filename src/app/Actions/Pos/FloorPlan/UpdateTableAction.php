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
 * Partial-update a table. Mutable: label / seats /
 * min_party / max_party / shape / notes / status /
 * display_order. NOT mutable: qr_token (use
 * {@see RegenerateTableQrAction} instead — semantically
 * different operation that invalidates a printed card),
 * floor_id (moving a table between floors is rare enough
 * to defer), uuid, company_id.
 */
final readonly class UpdateTableAction
{
    private const MUTABLE_FIELDS = [
        'label',
        'seats',
        'min_party',
        'max_party',
        'shape',
        'notes',
        'status',
        'display_order',
        // Phase 5.5 — single-table PATCH can update position
        // too (used by drag-and-stop on the planner). For bulk
        // layout saves prefer SaveFloorLayoutAction — one
        // transaction + one audit row per floor.
        'position_x',
        'position_y',
        'width',
        'height',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Table $table, array $attributes, User $actor): Table
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $table->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($table, $attributes, $actor, $companyId): Table {
            $changes = [];

            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $table->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum
                    ? $oldValue->value
                    : $oldValue;
                if ($oldComparable == $newValue) {
                    continue;
                }
                $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                $table->{$field} = $newValue;
            }

            if ($changes === []) {
                return $table->fresh();
            }

            $table->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'table.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                // branch_id derived through floor — we cache
                // the floor relation lookup once.
                branchId: $table->floor->branch_id,
                auditableType: Table::class,
                auditableId: $table->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $table->fresh();
        });
    }
}
