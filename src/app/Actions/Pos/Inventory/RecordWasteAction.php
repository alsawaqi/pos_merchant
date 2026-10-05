<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\StockMovementType;
use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\User;
use App\Models\WasteRecord;
use App\Support\Inventory\ContainerAmount;
use App\Support\MerchantTenantContext;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
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
 *
 * LAUNCH-P3 fix order 1, K3 — waste follows the selling rule (owner
 * decision 2026-10-02): recording waste is NEVER refused on the stock
 * numbers. A waste larger than the branch balance is recorded and the
 * balance goes below zero; {@see record()} returns a warning saying so,
 * and the manager sees the negative balance like any oversold stock.
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
        private IngredientUnitConverter $units,
    ) {}

    /**
     * @param  string|float|int  $quantity  ABSOLUTE positive amount, in [$unit]
     * @param  string|null  $unit  Entered unit (alt-unit name, or null =
     *                             base); converted to base before write (#13).
     */
    public function handle(
        Branch $branch,
        Ingredient $ingredient,
        string|float|int $quantity,
        WasteReason $reason,
        User $actor,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $unit = null,
    ): WasteRecord {
        return $this->record($branch, $ingredient, $quantity, $reason, $actor, $notes, $occurredAt, $unit)['record'];
    }

    /**
     * {@see handle()}, plus the branch balance around the waste and — when the
     * waste took it below zero — a warning for the person recording it.
     *
     * @param  int|null  $prepIngredientId  fix order 1, K4: the prep item this record is part of
     * @param  string|null  $wasteGroupUuid  fix order 1, K4: shared by every record of one waste event
     * @return array{record: WasteRecord, balance_before: string, balance_after: string, warning: ?string}
     */
    public function record(
        Branch $branch,
        Ingredient $ingredient,
        string|float|int $quantity,
        WasteReason $reason,
        User $actor,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $unit = null,
        ?int $prepIngredientId = null,
        ?string $wasteGroupUuid = null,
        // LAUNCH review add-on (D3) — waste entered by container: which one
        // and how many (the caller takes them from the breakdown).
        ?IngredientAltUnit $container = null,
        ?string $pieces = null,
    ): array {
        $companyId = $this->tenant->requiredId();

        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }
        if ((int) $ingredient->company_id !== $companyId) {
            throw new RuntimeException('Ingredient does not belong to your company.');
        }

        // #13 — convert the entered quantity to base units (the unit branch stock
        // is in), so the positivity + sufficient-stock checks compare like-for-like.
        // LAUNCH-P2 — ledger precision (4dp) once, used for record + movement.
        $absQty = round($this->units->toBase($ingredient, $quantity, $unit), StockDecimal::QUANTITY_SCALE);
        if ($absQty <= 0) {
            throw new RuntimeException('Waste quantity must be positive.');
        }

        // Closed-enum escape-hatch rule: 'other' must come with
        // an explanation. Other reasons keep notes optional.
        if ($reason === WasteReason::Other && (trim((string) $notes) === '')) {
            throw new RuntimeException("Notes are required when reason is 'other'.");
        }

        // LAUNCH-P3 K3 — no sufficient-stock refusal any more (sell-but-warn,
        // like a sale): the waste is a fact. (LAUNCH-P2 P2-6 already booked a
        // count's reconciliation shortfall whatever the balance.)

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
            $prepIngredientId,
            $wasteGroupUuid,
            $container,
            $pieces,
        ): array {
            // The balance this waste starts from, read under the row lock the
            // ledger write takes next (no row yet = 0).
            $before = (string) StockDecimal::quantity((string) (DB::table('pos_branch_stock')
                ->where('branch_id', $branch->id)
                ->where('ingredient_id', $ingredient->id)
                ->lockForUpdate()
                ->value('quantity') ?? '0'));

            // Step 1: insert the waste record. quantity stored
            // POSITIVE; the stock movement below is the signed
            // counterpart.
            /** @var WasteRecord $waste */
            $waste = WasteRecord::query()->create([
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredient->id,
                'quantity' => StockDecimal::quantity($absQty),
                'reason' => $reason->value,
                'unit_at_set' => $ingredient->unit?->value,
                // Freeze the cost at this moment so the "cost
                // of waste" report doesn't shift later.
                'unit_cost_at_time' => (string) $ingredient->default_unit_cost,
                'notes' => $notes,
                'recorded_by_user_id' => $actor->getKey(),
                'occurred_at' => $occurredAt,
                // LAUNCH-P3 K4 — the records of one prep waste are one event.
                'prep_ingredient_id' => $prepIngredientId,
                'waste_group_uuid' => $wasteGroupUuid,
                'container_id' => $container?->id,
                'pieces' => $container !== null ? $pieces : null,
                'container_label' => $container !== null ? ContainerAmount::label($ingredient, $container) : null,
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
                quantity: '-'.StockDecimal::quantity($absQty),
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
                    'quantity' => StockDecimal::quantity($absQty),
                    'reason' => $reason->value,
                    'unit_cost_at_time' => (string) $ingredient->default_unit_cost,
                    'total_cost' => number_format($absQty * (float) $ingredient->default_unit_cost, 3, '.', ''),
                    'notes' => $notes,
                ],
            ));

            $after = (string) StockDecimal::quantity((string) BigDecimal::of($before)->minus((string) StockDecimal::quantity($absQty)));
            $unit = (string) ($ingredient->unit?->value ?? '');
            $warning = $reason !== WasteReason::ReconciliationVariance && BigDecimal::of($after)->isNegative()
                ? sprintf(
                    'Recorded. %s is now below zero at this branch: it held %s %s and %s %s was wasted, so the balance is %s %s. Count it or receive stock to correct it.',
                    $ingredient->name,
                    $before,
                    $unit,
                    StockDecimal::quantity($absQty),
                    $unit,
                    $after,
                    $unit,
                )
                : null;

            return [
                'record' => $waste->fresh(['ingredient', 'branch']),
                'balance_before' => $before,
                'balance_after' => $after,
                'warning' => $warning,
            ];
        });
    }
}
