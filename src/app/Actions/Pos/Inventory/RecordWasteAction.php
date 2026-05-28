<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\StockMovementType;
use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\WasteRecord;
use App\Support\MerchantTenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5c — record a waste event at a branch.
 *
 * Two writes wrapped in one transaction:
 *
 *   1. pos_waste_records row — the QUERYABLE record. quantity
 *      stored ALWAYS POSITIVE for clean by-reason aggregation.
 *      Captures reason + notes + unit cost frozen at record
 *      time (so future ingredient-cost edits don't shift the
 *      "cost of waste" report retroactively).
 *
 *   2. pos_stock_movements row via WriteStockMovementAction
 *      with type=waste and signed-NEGATIVE quantity. This
 *      keeps the branch_stock invariant intact (running balance
 *      = SUM of movements) and gives the polymorphic back-
 *      reference (movement.reference_type = WasteRecord::class,
 *      reference_id = the waste row's id).
 *
 * Validation:
 *   - quantity > 0 (the caller passes the absolute amount)
 *   - reason in WasteReason enum
 *   - if reason = 'other', notes MUST be non-empty (the only
 *     way to keep the audit trail useful when the categorisation
 *     escape hatch is used)
 *   - branch + ingredient both in actor's tenant
 *   - branch_stock for this ingredient must currently hold
 *     ENOUGH to absorb the waste (we don't go negative — the
 *     merchant should record an Adjustment first if the
 *     accounting balance is wrong)
 *
 * Audit event: inventory.waste.recorded with full snapshot.
 *
 * No DeleteWasteAction — a recorded waste is a real-world
 * event. To correct an over-recorded amount the merchant
 * records a positive Adjustment movement with a note.
 */
final readonly class RecordWasteAction
{
    public function __construct(
        private WriteStockMovementAction $writeStockMovement,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  string|float|int  $quantity  ABSOLUTE positive amount
     */
    public function handle(
        Branch $branch,
        Ingredient $ingredient,
        string|float|int $quantity,
        WasteReason $reason,
        User $actor,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
    ): WasteRecord {
        $companyId = $this->tenant->requiredId();

        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }
        if ((int) $ingredient->company_id !== $companyId) {
            throw new RuntimeException('Ingredient does not belong to your company.');
        }

        // Coerce to a positive float for the comparison; cast
        // back to string for the DB write.
        $absQty = (float) $quantity;
        if ($absQty <= 0) {
            throw new RuntimeException('Waste quantity must be positive.');
        }

        // Closed-enum escape-hatch rule: 'other' must come with
        // an explanation. Other reasons keep notes optional.
        if ($reason === WasteReason::Other && (trim((string) $notes) === '')) {
            throw new RuntimeException("Notes are required when reason is 'other'.");
        }

        // Defensive sufficient-stock check. Phase 5a's
        // adjust-down flow has the same rule; we replicate it
        // here rather than letting the ledger go negative.
        $currentBalance = (float) DB::table('pos_branch_stock')
            ->where('branch_id', $branch->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('quantity') ?? 0.0;
        if ($currentBalance < $absQty) {
            throw new RuntimeException(sprintf(
                'Not enough stock to waste — branch holds %s but waste is %s.',
                number_format($currentBalance, 3, '.', ''),
                number_format($absQty, 3, '.', ''),
            ));
        }

        $occurredAt = $occurredAt instanceof DateTimeInterface
            ? Carbon::instance($occurredAt)
            : now();

        return DB::transaction(function () use (
            $branch,
            $ingredient,
            $absQty,
            $reason,
            $actor,
            $notes,
            $occurredAt,
            $companyId,
        ): WasteRecord {
            // Step 1: insert the waste record. quantity stored
            // POSITIVE; the stock movement below is the signed
            // counterpart.
            /** @var WasteRecord $waste */
            $waste = WasteRecord::query()->create([
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => number_format($absQty, 3, '.', ''),
                'reason' => $reason->value,
                'unit_at_set' => $ingredient->unit?->value,
                // Freeze the cost at this moment so the "cost
                // of waste" report doesn't shift later.
                'unit_cost_at_time' => (string) $ingredient->default_unit_cost,
                'notes' => $notes,
                'recorded_by_user_id' => $actor->getKey(),
                'occurred_at' => $occurredAt,
            ]);

            // Step 2: matching stock movement (signed-negative).
            // Delegates to WriteStockMovementAction so the
            // branch_stock invariant + the cross-tenant check
            // + the audit row stay consistent with every other
            // stock change path.
            $this->writeStockMovement->handle(
                branch: $branch,
                ingredient: $ingredient,
                type: StockMovementType::Waste,
                // SIGNED — flip to negative for the ledger.
                quantity: '-' . number_format($absQty, 3, '.', ''),
                unitCostAtTime: (string) $ingredient->default_unit_cost,
                referenceType: WasteRecord::class,
                referenceId: $waste->id,
                actor: $actor,
                note: $notes,
                occurredAt: $occurredAt,
            );

            // Step 3: waste-specific audit row. Distinct from
            // the inventory.movement.created row that
            // WriteStockMovementAction emits — this one
            // captures the reason taxonomy + the per-event
            // monetary cost.
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.waste.recorded',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: WasteRecord::class,
                auditableId: $waste->id,
                newValues: [
                    'ingredient_id' => $ingredient->id,
                    'ingredient_name' => $ingredient->name,
                    'quantity' => number_format($absQty, 3, '.', ''),
                    'reason' => $reason->value,
                    'unit_cost_at_time' => (string) $ingredient->default_unit_cost,
                    'total_cost' => number_format($absQty * (float) $ingredient->default_unit_cost, 3, '.', ''),
                    'notes' => $notes,
                ],
            ));

            return $waste->fresh(['ingredient', 'branch']);
        });
    }
}
