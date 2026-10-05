<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\RestockRequestStatus;
use App\Enums\StockMovementType;
use App\Models\IngredientAltUnit;
use App\Models\IngredientStock;
use App\Support\Inventory\Containers;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use App\Models\User;
use App\Support\MerchantTenantContext;
use App\Support\StockDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5c / Phase A — fulfil an Approved request FROM THE CENTRAL
 * WAREHOUSE: debit the central pool, credit the requesting branch,
 * transition to Fulfilled with resolution='warehouse'.
 *
 * Phase A (owner decision 2026-07-28): fulfilment is a real
 * warehouse→branch move. Every non-zero line writes a PAIRED ledger
 * entry — allocation_out at central (negative) + restock at the branch
 * (positive) — with the central rows locked and overdraw REFUSED (the
 * warehouse never goes negative, the same rule as manual allocation
 * and kitchen production). Before Phase A, fulfilment credited the
 * branch with no debit anywhere — stock appeared from nothing.
 *
 * When the shortage was resolved by a PURCHASE instead (branch bought
 * outside / supplier delivered directly), use
 * {@see ResolvePurchasedRestockRequestAction} — it closes the request
 * with NO stock movement so the goods are never counted twice.
 *
 * For each line:
 *   - allocated_quantity is taken from the optional override
 *     ($allocations keyed by line id), or defaults to
 *     line.quantity_requested when no override is provided.
 *   - 0 is a legitimate override — means "we approved but ended
 *     up unable to send this one". Line keeps allocated=0 and
 *     no movement is written for it.
 *   - Non-zero allocations write the paired central/branch legs;
 *     both reference the RestockRequestLine so the ledger traces
 *     back to the originating line.
 *
 * The request transitions to Fulfilled and the per-line
 * quantity_allocated is persisted, regardless of partial
 * vs full fulfilment. "Fulfilled" here means "we did what we
 * could" — not "we sent everything they asked for". The line-
 * level numbers tell the actual story.
 *
 * Only legal source state is Approved. Re-allocation is not
 * supported in this MVP — the action will refuse if status is
 * already Fulfilled.
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
        private ContainerBreakdownAction $breakdown,
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
            /** @var array<int, string> $containersNotMoved line id => why only the total moved */
            $containersNotMoved = [];

            // Pre-pass: resolve every line's allocated quantity and total
            // the central-pool demand per ingredient BEFORE writing anything,
            // so the overdraw check covers the whole request atomically.
            $plan = [];
            $needs = [];
            $names = [];
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
                        StockDecimal::quantity($allocated),
                        StockDecimal::quantity($requested),
                        $line->id,
                    ));
                }

                if ($allocated > 0 && $line->ingredient === null) {
                    throw new RuntimeException(sprintf(
                        'Line %d references a deleted ingredient — cannot allocate.',
                        $line->id,
                    ));
                }

                $plan[] = ['line' => $line, 'allocated' => $allocated];
                if ($allocated > 0) {
                    $ingredientId = (int) $line->ingredient->id;
                    $needs[$ingredientId] = ($needs[$ingredientId] ?? 0.0) + $allocated;
                    $names[$ingredientId] = $line->ingredient->name;
                }
            }

            // Phase A: the warehouse is the source — lock each central row
            // (deterministic order, the production-path deadlock rule) and
            // refuse overdraw. A shortage means HQ receives stock into the
            // warehouse first, reduces the line, or closes the request as
            // resolved-by-purchase instead.
            ksort($needs);
            foreach ($needs as $ingredientId => $needed) {
                $central = IngredientStock::query()
                    ->where('company_id', $companyId)
                    ->where('ingredient_id', $ingredientId)
                    ->lockForUpdate()
                    ->first();
                $available = $central === null ? 0.0 : (float) $central->quantity;
                if ($needed > $available + 1e-9) {
                    throw new RuntimeException(sprintf(
                        'Not enough central stock of %s: %s available, %s needed. Receive stock into the warehouse first, reduce the allocation, or close the request as purchased.',
                        $names[$ingredientId],
                        StockDecimal::quantity($available),
                        StockDecimal::quantity($needed),
                    ));
                }
            }

            foreach ($plan as $entry) {
                /** @var RestockRequestLine $line */
                $line = $entry['line'];
                $allocated = $entry['allocated'];

                $line->forceFill([
                    'quantity_allocated' => StockDecimal::quantity($allocated),
                ])->save();

                $perLine[$line->id] = StockDecimal::quantity($allocated);

                // Skip the stock movements for zero allocations —
                // a zero-quantity movement would be both confusing
                // in the ledger and rejected by the action's
                // own validation.
                if ($allocated === 0.0) {
                    continue;
                }

                $ingredient = $line->ingredient;

                // Fix order B-1 (M1) — which containers move with this share,
                // decided BEFORE the legs are written so the movement note can
                // say when only the total moved.
                [$breakdownLeaves, $breakdownSkipped] = $this->shareLeaves($ingredient, $line, $allocated);
                if ($breakdownSkipped !== null) {
                    $containersNotMoved[(int) $line->id] = $breakdownSkipped;
                }

                // Paired legs: the goods LEAVE the central pool and ARRIVE
                // at the requesting branch — conserved, like manual
                // allocation and branch transfers.
                $this->writeStockMovement->handle(
                    branch: null,
                    ingredient: $ingredient,
                    type: StockMovementType::AllocationOut,
                    quantity: -$allocated,
                    unitCostAtTime: (string) $ingredient->default_unit_cost,
                    referenceType: RestockRequestLine::class,
                    referenceId: $line->id,
                    actor: $actor,
                    note: sprintf(
                        'Sent to %s for restock request %s',
                        $branch->name,
                        $request->uuid,
                    ),
                );
                $inLeg = $this->writeStockMovement->handle(
                    branch: $branch,
                    ingredient: $ingredient,
                    type: StockMovementType::Restock,
                    quantity: StockDecimal::quantity($allocated),
                    unitCostAtTime: (string) $ingredient->default_unit_cost,
                    referenceType: RestockRequestLine::class,
                    referenceId: $line->id,
                    actor: $actor,
                    note: $breakdownSkipped === null
                        ? sprintf('Allocation from restock request %s', $request->uuid)
                        : sprintf('Allocation from restock request %s (total only: %s)', $request->uuid, $breakdownSkipped),
                );

                // LAUNCH review add-on (D4, tester call 10) — a line that names
                // its container moves that share of the breakdown warehouse →
                // branch. Fix order B-1 (M1) — only WHOLE containers (or, for
                // an item that allows part pieces, an exact share): a share
                // that is not whole containers moves the total only.
                if ($breakdownLeaves !== null && $breakdownLeaves !== []) {
                    $this->breakdown->move($ingredient, null, (int) $branch->id, $breakdownLeaves, 'allocation_out', 'allocation_in', $actor, [
                        'reference_type' => RestockRequestLine::class,
                        'reference_id' => (int) $line->id,
                        'to_stock_movement_id' => (int) $inLeg->id,
                    ]);
                }
            }

            // Transition status — even partial / zero-allocation
            // counts as Fulfilled (we did what we could). The
            // per-line numbers carry the actual story.
            $request->forceFill([
                'status' => RestockRequestStatus::Fulfilled->value,
                'fulfilled_at' => now(),
                'resolution' => 'warehouse',
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
                ] + ($containersNotMoved !== [] ? ['containers_not_moved' => $containersNotMoved] : []),
            ));

            return $request->fresh(['lines.ingredient', 'branch']);
        });
    }

    /**
     * Fix order B-1 (M1) — the LEAF containers a share of a container line
     * moves: leaf pieces of the whole line × allocated ÷ requested, worked out
     * in leaves (a crate is 12 bottles) BEFORE any rounding. They move only
     * when every leaf share is exact at 4 decimals and, for an item handled in
     * whole containers, a whole number. Otherwise only the total moves and the
     * reason is returned (never a 0.9996 or a half bottle).
     *
     * @return array{0: array<int, \Brick\Math\BigDecimal>|null, 1: string|null} [leaves, why only the total moved]
     */
    private function shareLeaves(\App\Models\Ingredient $ingredient, RestockRequestLine $line, float $allocated): array
    {
        if ($line->container_id === null || $line->pieces === null || (float) $line->quantity_requested <= 0) {
            return [null, null];
        }
        $container = IngredientAltUnit::withTrashed()->where('ingredient_id', $ingredient->id)->find($line->container_id);
        if ($container === null) {
            return [null, null];
        }

        $requested = Containers::decimal((string) $line->quantity_requested);
        $share = Containers::decimal(StockDecimal::quantity($allocated));
        $leaves = [];
        foreach (ContainerBreakdownAction::leaves($ingredient, [['container' => $container, 'pieces' => Containers::decimal((string) $line->pieces)]]) as $leafId => $leafPieces) {
            try {
                $part = $leafPieces->multipliedBy($share)->dividedBy($requested, StockDecimal::QUANTITY_SCALE, \Brick\Math\RoundingMode::UNNECESSARY);
            } catch (\Brick\Math\Exception\RoundingNecessaryException) {
                return [null, 'the share is not a whole number of containers'];
            }
            if (! $ingredient->allow_fractional_pieces && ! $part->isEqualTo($part->toScale(0, \Brick\Math\RoundingMode::DOWN))) {
                return [null, 'the share is not a whole number of containers'];
            }
            if ($part->isPositive()) {
                $leaves[(int) $leafId] = $part;
            }
        }

        return [$leaves, null];
    }
}
