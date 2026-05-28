<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\RestockRequestStatus;
use App\Enums\StockMovementType;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5c — fulfil an Approved request: write stock movements
 * at the requesting branch + transition to Fulfilled.
 *
 * For each line:
 *   - allocated_quantity is taken from the optional override
 *     ($allocations keyed by line id), or defaults to
 *     line.quantity_requested when no override is provided.
 *   - 0 is a legitimate override — means "we approved but ended
 *     up unable to send this one". Line keeps allocated=0 and
 *     no movement is written for it.
 *   - For non-zero allocations, a positive stock_movement of
 *     type=restock is written via WriteStockMovementAction
 *     (which keeps branch_stock in lock-step). reference_type
 *     = RestockRequestLine, reference_id = the line id, so the
 *     movement traces back to the originating line.
 *
 * The request transitions to Fulfilled and the per-line
 * quantity_allocated is persisted, regardless of partial
 * vs full fulfilment. "Fulfilled" here means "we did what we
 * could" — not "we sent everything they asked for". The line-
 * level numbers tell the actual story.
 *
 * Only legal source state is Approved. Re-allocation is not
 * supported in this MVP — the action will refuse if status is
 * already Fulfilled. A future enhancement could allow "add
 * to allocation" but that's deferred.
 *
 * Audit event: inventory.restock_request.allocated with the
 * per-line breakdown (line_id => allocated_qty).
 */
final readonly class AllocateRestockRequestAction
{
    public function __construct(
        private WriteStockMovementAction $writeStockMovement,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<int, numeric-string|float|int>  $allocations  Optional per-line overrides keyed by line.id
     */
    public function handle(RestockRequest $request, array $allocations, User $actor): RestockRequest
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $request->company_id !== $companyId) {
            abort(404);
        }

        if ($request->status !== RestockRequestStatus::Approved) {
            throw new RuntimeException(sprintf(
                'Only Approved requests can be allocated (current status: %s).',
                $request->status->value,
            ));
        }

        $request->load(['lines.ingredient', 'branch']);
        $branch = $request->branch;
        if ($branch === null) {
            throw new RuntimeException('Restock request has no branch.');
        }

        // Pre-flight: validate every override key is a real line
        // on THIS request. Stray ids would silently get ignored
        // by the per-line loop and a sloppy caller would think
        // their override applied — better to surface the
        // mismatch loudly.
        $lineIds = $request->lines->pluck('id')->all();
        foreach (array_keys($allocations) as $lineId) {
            if (! in_array((int) $lineId, $lineIds, true)) {
                throw new RuntimeException(sprintf(
                    'Allocation override for line %d does not belong to this request.',
                    $lineId,
                ));
            }
        }

        return DB::transaction(function () use ($request, $allocations, $actor, $branch, $companyId): RestockRequest {
            $oldStatus = $request->status->value;
            $perLine = [];

            foreach ($request->lines as $line) {
                /** @var RestockRequestLine $line */
                $requested = (float) $line->quantity_requested;
                // Default to the full requested amount when no
                // override provided.
                $allocated = isset($allocations[$line->id])
                    ? (float) $allocations[$line->id]
                    : $requested;

                if ($allocated < 0) {
                    throw new RuntimeException('Allocated quantity cannot be negative.');
                }
                if ($allocated > $requested) {
                    throw new RuntimeException(sprintf(
                        'Allocated %s exceeds requested %s for line %d.',
                        number_format($allocated, 3, '.', ''),
                        number_format($requested, 3, '.', ''),
                        $line->id,
                    ));
                }

                $line->forceFill([
                    'quantity_allocated' => number_format($allocated, 3, '.', ''),
                ])->save();

                $perLine[$line->id] = number_format($allocated, 3, '.', '');

                // Skip the stock movement for zero allocations —
                // a zero-quantity movement would be both confusing
                // in the ledger and rejected by the action's
                // own validation.
                if ($allocated === 0.0) {
                    continue;
                }

                $ingredient = $line->ingredient;
                if ($ingredient === null) {
                    throw new RuntimeException(sprintf(
                        'Line %d references a deleted ingredient — cannot allocate.',
                        $line->id,
                    ));
                }

                $this->writeStockMovement->handle(
                    branch: $branch,
                    ingredient: $ingredient,
                    type: StockMovementType::Restock,
                    quantity: number_format($allocated, 3, '.', ''),
                    unitCostAtTime: (string) $ingredient->default_unit_cost,
                    referenceType: RestockRequestLine::class,
                    referenceId: $line->id,
                    actor: $actor,
                    note: sprintf(
                        'Allocation from restock request %s',
                        $request->uuid,
                    ),
                );
            }

            // Transition status — even partial / zero-allocation
            // counts as Fulfilled (we did what we could). The
            // per-line numbers carry the actual story.
            $request->forceFill([
                'status' => RestockRequestStatus::Fulfilled->value,
                'fulfilled_at' => now(),
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.restock_request.allocated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: RestockRequest::class,
                auditableId: $request->id,
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'status' => RestockRequestStatus::Fulfilled->value,
                    'per_line_allocated' => $perLine,
                ],
            ));

            return $request->fresh(['lines.ingredient', 'branch']);
        });
    }
}
