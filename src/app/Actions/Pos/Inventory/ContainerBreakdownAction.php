<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\StockContainerBalance;
use App\Models\StockContainerMovement;
use App\Models\User;
use App\Support\Inventory\Containers;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH review add-on (B, owner option A) — the stock BREAKDOWN by container
 * at a branch (branch NULL = the warehouse): "2 × bottle 1.5 l + 3 × bottle
 * 500 ml" under the live total. It changes only here, and only from these
 * writers (inventory audit B3):
 *
 *   add   purchases (at the warehouse)
 *   move  warehouse → branch (a purchase split / direct delivery, Distribute,
 *         a restock allocation) and branch → branch transfers
 *   set   a portal count (exactly the containers counted) and the warehouse
 *         "Correct containers" action
 *   take  waste entered by container (never below 0: a clamp row records the
 *         part the breakdown did not hold)
 *
 * Sales, add-ons, production and cancellation waste (all in pos_api) never
 * touch it: recipe use lowers only the total. The breakdown is never used to
 * compute stock.
 *
 * Storage is in LEAF containers: 2 crates of 12 bottles are 24 × bottle. Each
 * change appends a row to pos_stock_container_movements (balance = Σ delta)
 * under the balance row's lock.
 */
final readonly class ContainerBreakdownAction
{
    /**
     * Leaf pieces for a list of (container, pieces): crates expand to their
     * bottles; two rows of the same leaf add up.
     *
     * @param  list<array{container: IngredientAltUnit, pieces: string|float|int|BigDecimal}>  $rows
     * @return array<int, BigDecimal> leaf container id => pieces
     */
    public static function leaves(Ingredient $ingredient, array $rows): array
    {
        $all = Containers::of($ingredient);
        $out = [];
        foreach ($rows as $row) {
            [$leaf, $per] = Containers::leaf($row['container'], $all);
            $id = (int) $leaf->id;
            // Fix order B-2 — a lowered inner count ("23 bottles" of 2 crates) is the leaves.
            if (isset($row['leaf_pieces']) && $row['leaf_pieces'] instanceof BigDecimal) {
                $out[$id] = ($out[$id] ?? BigDecimal::zero())->plus($row['leaf_pieces']);

                continue;
            }
            $pieces = $row['pieces'] instanceof BigDecimal ? $row['pieces'] : Containers::decimal($row['pieces']);
            $out[$id] = ($out[$id] ?? BigDecimal::zero())->plus($pieces->multipliedBy($per));
        }

        return array_filter($out, static fn (BigDecimal $p): bool => $p->isPositive());
    }

    /**
     * Add leaf pieces at a location (a purchase into the warehouse).
     *
     * @param  array<int, BigDecimal>  $leaves
     * @param  array{reference_type?: ?string, reference_id?: ?int, stock_movement_id?: ?int, occurred_at?: ?DateTimeInterface}  $ref
     */
    public function add(Ingredient $ingredient, ?int $branchId, array $leaves, string $reason, ?User $actor, array $ref = []): void
    {
        DB::transaction(function () use ($ingredient, $branchId, $leaves, $reason, $actor, $ref): void {
            $this->lockContainers($ingredient);
            foreach ($leaves as $containerId => $pieces) {
                $this->change($ingredient, $branchId, (int) $containerId, $pieces, $reason, $actor, $ref);
            }
        });
    }

    /**
     * Take leaf pieces from a location, never below 0: what the breakdown did
     * not hold is recorded as a clamp row (delta 0) so the ledger says why.
     *
     * @param  array<int, BigDecimal>  $leaves
     * @param  array{reference_type?: ?string, reference_id?: ?int, stock_movement_id?: ?int, occurred_at?: ?DateTimeInterface}  $ref
     */
    public function take(Ingredient $ingredient, ?int $branchId, array $leaves, string $reason, ?User $actor, array $ref = []): void
    {
        DB::transaction(function () use ($ingredient, $branchId, $leaves, $reason, $actor, $ref): void {
            $this->lockContainers($ingredient);
            foreach ($leaves as $containerId => $pieces) {
                $this->change($ingredient, $branchId, (int) $containerId, $pieces->negated(), $reason, $actor, $ref);
            }
        });
    }

    /**
     * Move leaf pieces from one location to another (a transfer, a
     * distribution, a restock allocation, a purchase split): taken at the
     * source (clamped at 0) and added in full at the destination — the
     * containers stated are the ones that arrived.
     *
     * @param  array<int, BigDecimal>  $leaves
     * @param  array{reference_type?: ?string, reference_id?: ?int, stock_movement_id?: ?int, to_stock_movement_id?: ?int, occurred_at?: ?DateTimeInterface}  $ref
     */
    public function move(Ingredient $ingredient, ?int $fromBranchId, ?int $toBranchId, array $leaves, string $outReason, string $inReason, ?User $actor, array $ref = []): void
    {
        DB::transaction(function () use ($ingredient, $fromBranchId, $toBranchId, $leaves, $outReason, $inReason, $actor, $ref): void {
            $this->lockContainers($ingredient);
            foreach ($leaves as $containerId => $pieces) {
                $this->change($ingredient, $fromBranchId, (int) $containerId, $pieces->negated(), $outReason, $actor, $ref);
                $this->change($ingredient, $toBranchId, (int) $containerId, $pieces, $inReason, $actor, array_merge($ref, [
                    'stock_movement_id' => $ref['to_stock_movement_id'] ?? ($ref['stock_movement_id'] ?? null),
                ]));
            }
        });
    }

    /**
     * Set a location's breakdown to exactly these leaf pieces (a count, or
     * the warehouse correction): every other container goes to 0. Stamps
     * containers_counted_at on the balance row.
     *
     * @param  array<int, BigDecimal>  $leaves
     * @param  array{reference_type?: ?string, reference_id?: ?int, stock_movement_id?: ?int, occurred_at?: ?DateTimeInterface}  $ref
     */
    public function set(Ingredient $ingredient, ?int $branchId, array $leaves, string $reason, ?User $actor, array $ref = [], ?int $posStaffId = null): void
    {
        DB::transaction(function () use ($ingredient, $branchId, $leaves, $reason, $actor, $ref, $posStaffId): void {
            $this->lockContainers($ingredient);
            $current = $this->balances($ingredient, $branchId)->keyBy('container_id');
            $ids = array_unique(array_merge(array_map('intval', array_keys($leaves)), $current->keys()->map(static fn ($id): int => (int) $id)->all()));
            sort($ids);
            foreach ($ids as $containerId) {
                // Fix order B-1 (L6) — the TARGET is passed: the delta is taken
                // from the locked balance row, so a purchase landing between
                // the read and the write cannot make "set" inexact.
                $this->change($ingredient, $branchId, $containerId, $leaves[$containerId] ?? BigDecimal::zero(), $reason, $actor, $ref, $posStaffId, true);
            }
            $this->stamp($ingredient, $branchId, 'containers_counted_at', $ref['occurred_at'] ?? null);
        });
    }

    /**
     * A total-only count (no containers): leave the breakdown and stamp when it
     * happened (tester call 9 c).
     */
    public function stampTotalOnly(Ingredient $ingredient, int $branchId, ?DateTimeInterface $at = null): void
    {
        $this->stamp($ingredient, $branchId, 'containers_total_count_at', $at);
    }

    /**
     * The location's balance rows (pieces > 0 and = 0 alike).
     *
     * @return \Illuminate\Support\Collection<int, StockContainerBalance>
     */
    public function balances(Ingredient $ingredient, ?int $branchId): \Illuminate\Support\Collection
    {
        return StockContainerBalance::query()
            ->where('company_id', (int) $ingredient->company_id)
            ->where('ingredient_id', $ingredient->id)
            ->when($branchId === null, static fn ($q) => $q->whereNull('branch_id'), static fn ($q) => $q->where('branch_id', $branchId))
            ->get();
    }

    /**
     * Apply one signed change to one leaf balance + its ledger row. A
     * negative change larger than the balance takes it to 0 and writes the
     * remainder as a clamp row.
     *
     * @param  array{reference_type?: ?string, reference_id?: ?int, stock_movement_id?: ?int, occurred_at?: ?DateTimeInterface}  $ref
     */
    private function change(Ingredient $ingredient, ?int $branchId, int $containerId, BigDecimal $amount, string $reason, ?User $actor, array $ref, ?int $posStaffId = null, bool $isTarget = false): void
    {
        $amount = $amount->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
        if (! $isTarget && $amount->isZero()) {
            return;
        }
        $companyId = (int) $ingredient->company_id;

        // Fix order B-1 (L6) — the first write of a (location, container)
        // inserts its zero row ON CONFLICT DO NOTHING, then every writer locks
        // the row: two first writers no longer both insert (a unique-index 500).
        $find = static fn () => StockContainerBalance::query()
            ->where('company_id', $companyId)
            ->where('ingredient_id', $ingredient->id)
            ->where('container_id', $containerId)
            ->when($branchId === null, static fn ($q) => $q->whereNull('branch_id'), static fn ($q) => $q->where('branch_id', $branchId));
        if (! $find()->exists()) {
            DB::table('pos_stock_container_balances')->insertOrIgnore([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'ingredient_id' => $ingredient->id,
                'container_id' => $containerId,
                'pieces' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        /** @var StockContainerBalance $balance */
        $balance = $find()->lockForUpdate()->firstOrFail();

        $have = Containers::decimal($balance->getAttributes()['pieces'] ?? '0');
        $delta = $isTarget ? $amount->minus($have) : $amount;
        if ($delta->isZero()) {
            return;
        }
        $after = $have->plus($delta);
        $clamped = BigDecimal::zero();
        if ($after->isNegative()) {
            $clamped = $after->negated();
            $after = BigDecimal::zero();
            $delta = $have->negated();
        }

        $balance->pieces = (string) $after;
        $balance->save();

        $occurredAt = isset($ref['occurred_at']) && $ref['occurred_at'] !== null ? Carbon::instance($ref['occurred_at']) : now();
        $row = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'ingredient_id' => $ingredient->id,
            'container_id' => $containerId,
            'stock_movement_id' => $ref['stock_movement_id'] ?? null,
            'reference_type' => $ref['reference_type'] ?? null,
            'reference_id' => $ref['reference_id'] ?? null,
            'recorded_by_user_id' => $actor?->getKey(),
            'recorded_by_pos_staff_id' => $posStaffId,
            'occurred_at' => $occurredAt,
        ];

        if (! $delta->isZero()) {
            StockContainerMovement::query()->create($row + [
                'delta_pieces' => (string) $delta,
                'pieces_after' => (string) $after,
                'reason' => $reason,
            ]);
        }
        if ($clamped->isPositive()) {
            // What the breakdown did not hold: the shelf had fewer of this
            // container than the books of containers said (opened, used in
            // recipes). Recorded with delta 0 so Σ delta still equals the balance.
            StockContainerMovement::query()->create($row + [
                'delta_pieces' => '0',
                'pieces_after' => (string) $after,
                'reason' => 'clamp',
            ]);
        }
    }

    /**
     * Fix order B-1 (L6) — a breakdown write holds a SHARE lock on the item's
     * container rows for its transaction, so a resize (which locks the row
     * FOR UPDATE and checks usage inside its own transaction) waits for it and
     * then sees the new rows — a used container is never re-sized under a
     * write in flight. (No-op on SQLite.)
     */
    private function lockContainers(Ingredient $ingredient): void
    {
        IngredientAltUnit::query()->where('ingredient_id', $ingredient->id)->sharedLock()->pluck('id');
    }

    private function stamp(Ingredient $ingredient, ?int $branchId, string $column, ?DateTimeInterface $at): void
    {
        $when = $at !== null ? Carbon::instance($at) : now();
        // A location that never held the item gets its (zero) balance row, so
        // the date has somewhere to live; a zero row keeps balance = Σ movements.
        // Fix order B-1 (L6) — insert-or-ignore, then update: two first
        // writers never collide on the unique row.
        if ($branchId === null) {
            $updated = DB::table('pos_ingredient_stock')
                ->where('company_id', (int) $ingredient->company_id)
                ->where('ingredient_id', $ingredient->id)
                ->update([$column => $when]);
            if ($updated === 0) {
                DB::table('pos_ingredient_stock')->insertOrIgnore([
                    'company_id' => (int) $ingredient->company_id,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => '0',
                    $column => $when,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('pos_ingredient_stock')
                    ->where('company_id', (int) $ingredient->company_id)
                    ->where('ingredient_id', $ingredient->id)
                    ->update([$column => $when]);
            }

            return;
        }
        $updated = DB::table('pos_branch_stock')
            ->where('branch_id', $branchId)
            ->where('ingredient_id', $ingredient->id)
            ->update([$column => $when]);
        if ($updated === 0) {
            DB::table('pos_branch_stock')->insertOrIgnore([
                'branch_id' => $branchId,
                'ingredient_id' => $ingredient->id,
                'quantity' => '0',
                $column => $when,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('pos_branch_stock')
                ->where('branch_id', $branchId)
                ->where('ingredient_id', $ingredient->id)
                ->update([$column => $when]);
        }
    }
}
