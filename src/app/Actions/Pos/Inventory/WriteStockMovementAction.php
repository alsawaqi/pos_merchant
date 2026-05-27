<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\PosStaff;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\MerchantTenantContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5a — the canonical entry point for ANY stock change.
 *
 * Every other inventory Action delegates here: AdjustStockAction,
 * RestockAction, and (Phase 8) the order-driven sale-consumption
 * pipeline. Centralising the write means three invariants hold
 * everywhere they should:
 *
 *   1. Branch + ingredient both belong to the actor's company —
 *      cross-tenant cross-pollination is impossible.
 *   2. pos_branch_stock.quantity stays in lock-step with
 *      SUM(pos_stock_movements.quantity) per (branch, ingredient).
 *      Wrapped in DB::transaction; either both writes happen or
 *      neither does.
 *   3. The branch_stock row is created lazily on first movement
 *      (the merchant doesn't have to "open" stock at a branch
 *      before the first restock). last_movement_at always
 *      reflects the most recent change.
 *
 * The recorded_by split (user_id vs pos_staff_id) lets us tell
 * who triggered a movement: portal users for manual entries,
 * POS staff for sale-driven consumption (Phase 8).
 */
final readonly class WriteStockMovementAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  string|float|int  $quantity         Signed: positive inflow, negative outflow
     * @param  string|float|int  $unitCostAtTime   Unit cost at this moment (OMR/unit)
     * @param  string|null       $referenceType    Polymorphic FK (Order::class, etc.)
     * @param  int|null          $referenceId      Polymorphic FK id
     * @param  User|PosStaff|null $actor           Who triggered the movement
     * @param  string|null       $note             Free-text reason (required by callers on Adjustment / Waste)
     * @param  DateTimeInterface|null $occurredAt  When the movement happened (defaults to now)
     */
    public function handle(
        Branch $branch,
        Ingredient $ingredient,
        StockMovementType $type,
        string|float|int $quantity,
        string|float|int $unitCostAtTime = 0,
        ?string $referenceType = null,
        ?int $referenceId = null,
        User|PosStaff|null $actor = null,
        ?string $note = null,
        ?DateTimeInterface $occurredAt = null,
    ): StockMovement {
        $companyId = $this->tenant->requiredId();

        // Cross-tenant defence in depth — even though callers
        // resolve branch + ingredient via tenant-scoped queries,
        // we re-check here so internal callers (Phase 8 order
        // pipeline) can't accidentally route a sale-consumption
        // through the wrong company.
        if ((int) $branch->company_id !== $companyId) {
            throw new RuntimeException('Branch does not belong to your company.');
        }
        if ((int) $ingredient->company_id !== $companyId) {
            throw new RuntimeException('Ingredient does not belong to your company.');
        }

        $occurredAt = $occurredAt instanceof DateTimeInterface
            ? Carbon::instance($occurredAt)
            : now();

        $actorUserId = $actor instanceof User ? $actor->getKey() : null;
        $actorStaffId = $actor instanceof PosStaff ? $actor->getKey() : null;

        return DB::transaction(function () use (
            $branch,
            $ingredient,
            $type,
            $quantity,
            $unitCostAtTime,
            $referenceType,
            $referenceId,
            $actorUserId,
            $actorStaffId,
            $note,
            $occurredAt,
            $companyId,
            $actor,
        ): StockMovement {
            // Step 1: append the ledger row. Never updated, never
            // deleted — corrections are NEW Adjustment rows.
            /** @var StockMovement $movement */
            $movement = StockMovement::query()->create([
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredient->id,
                'movement_type' => $type->value,
                'quantity' => (string) $quantity,
                'unit_cost_at_time' => (string) $unitCostAtTime,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'recorded_by_user_id' => $actorUserId,
                'recorded_by_pos_staff_id' => $actorStaffId,
                'note' => $note,
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);

            // Step 2: upsert the running balance. firstOrCreate
            // handles the lazy-creation case (branch never stocked
            // this ingredient before); the subsequent increment
            // moves the running total by the signed delta.
            /** @var BranchStock $balance */
            $balance = BranchStock::query()->firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'ingredient_id' => $ingredient->id,
                ],
                [
                    'quantity' => '0.000',
                ],
            );
            // increment() accepts a numeric delta and writes via
            // a SQL `quantity = quantity + :delta` statement,
            // which is safe under concurrent writes because the
            // outer DB::transaction wraps it (Postgres' default
            // READ COMMITTED gives us per-row consistency).
            $balance->increment('quantity', (float) $quantity);
            $balance->forceFill(['last_movement_at' => $occurredAt])->save();

            // Step 3: audit row. Distinct from the stock_movement
            // ledger — that's the accounting record, this is the
            // security/forensics record. Both are append-only.
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.movement.created',
                actorUserId: $actorUserId,
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: StockMovement::class,
                auditableId: $movement->id,
                newValues: [
                    'ingredient_id' => $ingredient->id,
                    'ingredient_name' => $ingredient->name,
                    'movement_type' => $type->value,
                    'quantity' => (string) $quantity,
                    'unit_cost_at_time' => (string) $unitCostAtTime,
                    'note' => $note,
                ],
            ));

            return $movement->fresh();
        });
    }
}
