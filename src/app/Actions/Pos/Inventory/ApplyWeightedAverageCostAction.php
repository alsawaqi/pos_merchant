<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\Recipes\PrepGraph;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH-P2 P2-2 (LAUNCH-001 M9) — weighted-average ingredient cost.
 *
 * Every purchase that brings stock in blends the price actually paid into
 * the ingredient's average cost (pos_ingredients.default_unit_cost, per
 * BASE unit, 6 decimals):
 *
 *   new = (on_hand × old + received × paid) / (on_hand + received)
 *   on_hand ≤ 0  →  new = paid
 *
 * on_hand is the company-wide balance BEFORE this receipt: the central
 * warehouse plus every branch (negative branch balances included — an
 * oversold branch lowers on_hand). The caller runs this INSIDE its
 * transaction and BEFORE it writes the inflow movement. The ingredient row
 * is locked FOR UPDATE, so two receipts of the same ingredient average one
 * after the other. Delivery and other receipt charges are separate expenses
 * and never enter the cost. The change is audited with old cost, new cost
 * and the receipt it came from.
 *
 * The caller's Ingredient instance is updated in place so later legs of the
 * same receipt (allocation to branches) stamp the new average.
 */
final readonly class ApplyWeightedAverageCostAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  string|float|int  $receivedQuantity  base units entering stock (> 0)
     * @param  string|float|int  $paidUnitCost  price paid per base unit (net of tax)
     * @param  array<string, mixed>  $context  audit context (receipt / line / source)
     * @return array{old: string, new: string, on_hand: string}
     */
    public function handle(
        Ingredient $ingredient,
        string|float|int $receivedQuantity,
        string|float|int $paidUnitCost,
        ?User $actor,
        array $context = [],
    ): array {
        $received = self::decimal($receivedQuantity);
        $paid = self::decimal($paidUnitCost);
        if ($received->isNegativeOrZero()) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }
        if ($paid->isNegative()) {
            throw new RuntimeException('The price paid cannot be negative.');
        }

        return DB::transaction(function () use ($ingredient, $received, $paid, $actor, $context): array {
            /** @var Ingredient $locked */
            $locked = Ingredient::query()
                ->withoutGlobalScopes()
                ->whereKey($ingredient->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $old = self::decimal($locked->getRawOriginal('default_unit_cost') ?? 0);
            $onHand = self::decimal(
                DB::table('pos_branch_stock')->where('ingredient_id', $locked->id)->sum('quantity'),
            )->plus(self::decimal(
                DB::table('pos_ingredient_stock')
                    ->where('company_id', $locked->company_id)
                    ->where('ingredient_id', $locked->id)
                    ->sum('quantity'),
            ));

            $new = $onHand->isNegativeOrZero()
                ? $paid->toScale(StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP)
                : $onHand->multipliedBy($old)
                    ->plus($received->multipliedBy($paid))
                    ->dividedBy($onHand->plus($received), StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP);

            $oldText = (string) StockDecimal::unitCost((string) $old);
            $newText = (string) StockDecimal::unitCost((string) $new);

            if (! $new->isEqualTo($old)) {
                $locked->forceFill(['default_unit_cost' => $newText])->save();
                // LAUNCH-P3 — prep items cost through this ingredient.
                PrepGraph::forget((int) $locked->company_id);
            }
            // Keep the caller's instance in step (it stamps later legs).
            $ingredient->forceFill(['default_unit_cost' => $newText]);
            $ingredient->syncOriginalAttribute('default_unit_cost');

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient.cost_averaged',
                actorUserId: $actor?->getKey() !== null ? (int) $actor->getKey() : null,
                companyId: (int) $locked->company_id,
                auditableType: Ingredient::class,
                auditableId: (int) $locked->id,
                oldValues: ['default_unit_cost' => $oldText],
                newValues: array_merge([
                    'default_unit_cost' => $newText,
                    'on_hand_before' => StockDecimal::quantity((string) $onHand),
                    'received_quantity' => StockDecimal::quantity((string) $received),
                    'paid_unit_cost' => StockDecimal::unitCost((string) $paid),
                ], $context),
            ));

            return [
                'old' => $oldText,
                'new' => $newText,
                'on_hand' => (string) StockDecimal::quantity((string) $onHand),
            ];
        });
    }

    /** Exact decimal from a DB / request value (SQLite hands back floats). */
    public static function decimal(string|float|int|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            $value = number_format($value, 8, '.', '');
        }

        return BigDecimal::of(trim((string) $value));
    }
}
