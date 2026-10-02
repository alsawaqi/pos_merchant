<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

/**
 * LAUNCH-P2 P2-3 — the unit picker on goods received.
 *
 * A receipt line is entered in a purchase unit — the ingredient's base unit,
 * its automatic metric pair (kg↔g, l↔ml), one of its extra units (box,
 * carton) or its piece unit ({@see IngredientUnitConverter::PIECE_UNIT}) —
 * with the quantity, the branch split and optionally the PRICE PER THAT UNIT.
 * This turns it into what the stock side stores:
 *
 *   quantity       base units, 4 decimals (25 kg of a gram ingredient → 25000)
 *   allocations    the branch split in base units (rounding never makes the
 *                  split exceed the line)
 *   line_cost      quantity × unit price, rounded ONCE to 3 decimals (OMR);
 *                  when no unit price is given the typed line cost stands
 *   paid_unit_cost the unit price ÷ base units per purchase unit, 6 decimals
 *                  (NULL without a unit price: the receive divides the line
 *                  cost by the base quantity)
 *
 * and what the receipt line remembers (purchase_unit / purchase_quantity /
 * unit_price). Products are received in pieces: no unit, factor 1.
 */
final readonly class ResolvePurchaseLineUnitAction
{
    private const MAX_BASE_QUANTITY = '999999999.9999';

    private const MAX_LINE_COST = '999999999.999';

    public function __construct(
        private IngredientUnitConverter $units,
    ) {}

    /**
     * @param  array<string, mixed>  $row  the request line (quantity, unit?, unit_price?, line_cost?)
     * @param  list<array{branch: Branch, quantity: string|float|int}>  $allocations  split in the ENTERED unit
     * @return array{quantity: string, allocations: list<array{branch: Branch, quantity: string}>, line_cost: string, purchase_unit: string|null, purchase_quantity: string|null, unit_price: string|null, paid_unit_cost: string|null}
     */
    public function handle(?Ingredient $ingredient, array $row, array $allocations): array
    {
        $unit = isset($row['unit']) && trim((string) $row['unit']) !== '' ? trim((string) $row['unit']) : null;
        if ($ingredient === null && $unit !== null) {
            throw new RuntimeException('Products are received in pieces — remove the unit from this line.');
        }

        $factor = $ingredient !== null
            ? self::decimal($this->units->factorFor($ingredient, $unit))
            : BigDecimal::one();
        $entered = self::decimal($row['quantity'] ?? 0);

        $base = $entered->multipliedBy($factor)->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
        if (! $base->isPositive()) {
            throw new RuntimeException('The received quantity is too small for this unit — enter a larger quantity or a smaller unit.');
        }
        if ($base->isGreaterThan(self::MAX_BASE_QUANTITY)) {
            throw new RuntimeException('The converted quantity exceeds the maximum storable amount of 999,999,999.9999 in the base unit — use a smaller quantity or unit.');
        }

        // The branch split, in base units. Each share rounds on its own, so
        // a split that used the whole line in the entered unit could exceed
        // it by a rounding step: the largest share absorbs that drift.
        $split = [];
        $sum = BigDecimal::zero();
        foreach ($allocations as $allocation) {
            $share = self::decimal($allocation['quantity'])->multipliedBy($factor)
                ->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
            $split[] = ['branch' => $allocation['branch'], 'quantity' => $share];
            $sum = $sum->plus($share);
        }
        $excess = $sum->minus($base);
        if ($excess->isPositive() && $excess->isLessThanOrEqualTo(BigDecimal::of('0.0001')->multipliedBy(max(1, count($split))))) {
            $largest = 0;
            foreach ($split as $i => $share) {
                if ($share['quantity']->isGreaterThan($split[$largest]['quantity'])) {
                    $largest = $i;
                }
            }
            $split[$largest]['quantity'] = $split[$largest]['quantity']->minus($excess);
        }

        $unitPrice = isset($row['unit_price']) && $row['unit_price'] !== '' && $row['unit_price'] !== null
            ? self::decimal($row['unit_price'])
            : null;
        if ($unitPrice !== null && $unitPrice->isNegative()) {
            throw new RuntimeException('The unit price cannot be negative.');
        }

        if ($unitPrice !== null) {
            $lineCost = $entered->multipliedBy($unitPrice)->toScale(3, RoundingMode::HALF_UP);
            $paid = $unitPrice->dividedBy($factor, StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP);
        } else {
            $lineCost = self::decimal($row['line_cost'] ?? 0)->toScale(3, RoundingMode::HALF_UP);
            $paid = null;
        }
        if ($lineCost->isGreaterThan(self::MAX_LINE_COST)) {
            throw new RuntimeException('The line cost exceeds the maximum of 999,999,999.999.');
        }

        $baseUnit = $ingredient?->unit?->value;
        $purchaseUnit = ($unit !== null && $unit !== $baseUnit) ? $unit : null;
        $remember = $purchaseUnit !== null || $unitPrice !== null;

        return [
            'quantity' => (string) StockDecimal::quantity((string) $base),
            'allocations' => array_map(static fn (array $share): array => [
                'branch' => $share['branch'],
                'quantity' => (string) StockDecimal::quantity((string) $share['quantity']),
            ], $split),
            'line_cost' => (string) $lineCost,
            'purchase_unit' => $purchaseUnit,
            'purchase_quantity' => $remember ? StockDecimal::quantity((string) $entered) : null,
            'unit_price' => $unitPrice !== null ? StockDecimal::unitCost((string) $unitPrice) : null,
            'paid_unit_cost' => $paid !== null ? StockDecimal::unitCost((string) $paid) : null,
        ];
    }

    private static function decimal(string|float|int|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        return BigDecimal::of(trim((string) $value));
    }
}
