<?php

declare(strict_types=1);

namespace App\Actions\Pos\FloorPlan;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Floor;
use App\Models\Table;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5.5 — bulk-save positions for every table on a floor
 * after a drag-and-drop session.
 *
 * Why a bulk endpoint instead of N single PATCHes:
 *   - The merchant drags 12 tables, then clicks Save. We want
 *     ONE audit row ("layout saved on Main Floor: 12 tables
 *     moved") instead of 12 noisy table.updated rows that
 *     bury the actual operational change.
 *   - One DB transaction = if any row fails (e.g. cross-tenant
 *     UUID slipped in), the whole save rolls back and the UI
 *     can re-render the pre-save state without inconsistency.
 *   - One round-trip = the planner stays responsive even on a
 *     flaky merchant connection (no N pending HTTP requests).
 *
 * Cross-tenant defence: every UUID in the payload must belong
 * to a table on THIS floor. Different floor or different
 * company → RuntimeException → 422. We do not silently skip
 * — a payload referencing the wrong table is a real bug or
 * an attack, not noise to swallow.
 *
 * Audit event: floor.layout_saved with old/new positions for
 * every moved table. Unmoved tables are NOT included (no need
 * to inflate the payload with stale data).
 */
final readonly class SaveFloorLayoutAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<int, array{uuid: string, position_x: int|null, position_y: int|null, width?: int|null, height?: int|null}>  $items
     * @return array<int, Table>  fresh-loaded tables in their new state, in input order
     */
    public function handle(Floor $floor, array $items, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $floor->company_id !== $companyId) {
            abort(404);
        }

        // Bulk-load every table on this floor in ONE query so
        // we can validate UUIDs against the live set without
        // N+1 round trips. Soft-deleted rows are excluded — a
        // payload referencing a deleted table is treated like
        // any other bogus UUID.
        /** @var array<string, Table> $byUuid */
        $byUuid = Table::query()
            ->where('floor_id', $floor->id)
            ->get()
            ->keyBy('uuid')
            ->all();

        // Validate the entire payload BEFORE any write. If a
        // single UUID is bogus or belongs to another floor,
        // the whole save aborts — no partial-write surprises.
        foreach ($items as $idx => $item) {
            if (! isset($byUuid[$item['uuid']])) {
                throw new RuntimeException(
                    "Item {$idx} (uuid={$item['uuid']}) is not a table on this floor.",
                );
            }
        }

        return DB::transaction(function () use ($floor, $items, $byUuid, $actor, $companyId): array {
            $movedSummary = [];
            $updated = [];

            foreach ($items as $item) {
                $table = $byUuid[$item['uuid']];

                $oldPos = [
                    'x' => $table->position_x,
                    'y' => $table->position_y,
                    'w' => $table->width,
                    'h' => $table->height,
                ];
                $newPos = [
                    'x' => $item['position_x'] ?? null,
                    'y' => $item['position_y'] ?? null,
                    'w' => $item['width'] ?? $table->width,
                    'h' => $item['height'] ?? $table->height,
                ];

                // Skip rows where nothing about position
                // actually changed — keeps the audit payload
                // focused on the real movements.
                if ($oldPos === $newPos) {
                    $updated[] = $table;
                    continue;
                }

                $table->position_x = $newPos['x'];
                $table->position_y = $newPos['y'];
                $table->width = $newPos['w'];
                $table->height = $newPos['h'];
                $table->save();

                $movedSummary[] = [
                    'uuid' => $table->uuid,
                    'label' => $table->label,
                    'old' => $oldPos,
                    'new' => $newPos,
                ];
                $updated[] = $table->fresh();
            }

            // Only write the audit row if something actually
            // moved — a "no-op save" is real (the user opened
            // the planner, looked, hit Save without dragging)
            // and we shouldn't pollute the log with it.
            if ($movedSummary !== []) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'floor.layout_saved',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    branchId: $floor->branch_id,
                    auditableType: Floor::class,
                    auditableId: $floor->id,
                    newValues: [
                        'moved_count' => count($movedSummary),
                        'tables' => $movedSummary,
                    ],
                ));
            }

            return $updated;
        });
    }
}
