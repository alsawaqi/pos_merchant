<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\Product;
use App\Models\ProductPack;
use App\Support\Inventory\ContainerAmount;
use App\Support\Inventory\Containers;
use App\Support\Inventory\Packs;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

/**
 * LAUNCH review add-on (C1, C2, D3) — a Purchases line bought BY CONTAINER:
 * an ingredient + one of its containers (or a physical item + one of its
 * packs), how many (`pieces`), and an amount that fills in as pieces × size
 * and may be LOWERED (a broken bottle: 11 bottles = 11 l) but never RAISED.
 * The price paid for the whole line (`line_cost`, required, 0 allowed = a
 * free line) is typed on every line; the cost per base unit is worked out
 * from it by the receive (line cost ÷ amount, 6 decimals, HALF_UP).
 *
 * The branch split is entered in PIECES: each share gets that part of the
 * line's amount (amount × share pieces ÷ line pieces, 4 decimals; the largest
 * share absorbs a rounding step so the split never exceeds the line). The
 * breakdown by container (ingredients only, in LEAF containers: 2 crates of
 * 12 = 24 bottles) is added at the warehouse and moved with each share.
 */
final readonly class ResolvePurchaseContainerLineAction
{
    public function __construct(
        private IngredientUnitConverter $units,
    ) {}

    /**
     * @param  array<string, mixed>  $row  the request line
     * @param  list<array{branch: Branch, pieces: string|float|int}>  $allocations  the split, in pieces
     * @return array{quantity: string, allocations: list<array{branch: Branch, quantity: string, leaves: array<int, BigDecimal>}>, line_cost: string, pieces: string, container: ?IngredientAltUnit, pack: ?ProductPack, container_label: string, container_factor: string, leaves: array<int, BigDecimal>}
     */
    public function handle(?Ingredient $ingredient, ?Product $product, array $row, array $allocations): array
    {
        $pieces = Containers::decimal($row['pieces'] ?? '0');

        if ($ingredient !== null) {
            $container = Containers::findByUuid($ingredient, (string) ($row['container_uuid'] ?? ''));
            if ($container === null) {
                throw new RuntimeException(sprintf('The container on the "%s" line is not one of its containers.', $ingredient->name));
            }
            // Fix order B-2 — "23 bottles" of 2 crates of 12 (one broken): leaf_pieces.
            $rows = [ContainerAmount::row($ingredient, $container, $pieces, $row['leaf_pieces'] ?? null)];
            $amountUnit = isset($row['amount_unit']) && trim((string) $row['amount_unit']) !== '' ? trim((string) $row['amount_unit']) : null;
            $base = ContainerAmount::amount($ingredient, $rows, $row['amount'] ?? null, $amountUnit, $this->units);
            $factor = Containers::decimal((string) $container->factor);
            $label = ContainerAmount::label($ingredient, $container);
            $leaves = ContainerBreakdownAction::leaves($ingredient, $rows);
            $pack = null;
        } elseif ($product !== null) {
            $pack = Packs::findByUuid($product, (string) ($row['pack_uuid'] ?? ''));
            if ($pack === null) {
                throw new RuntimeException(sprintf('The pack on the "%s" line is not one of its packs.', $product->name));
            }
            if (! $pieces->isPositive()) {
                throw new RuntimeException(sprintf('Enter how many %s of "%s".', $pack->name, $product->name));
            }
            $factor = Containers::decimal((string) $pack->pieces);
            $cap = $pieces->multipliedBy($factor)->toScale(3, RoundingMode::HALF_UP);
            // Fix order B-1 (L1) — the same maximum as a loose line (999,999.999 pieces).
            if ($cap->isGreaterThan(BigDecimal::of('999999.999'))) {
                throw new RuntimeException(sprintf('%s: %s pieces is more than the 999,999.999 pieces one line can hold.', $product->name, Containers::trim((string) $cap)));
            }
            $amount = $row['amount'] ?? null;
            $base = $amount === null || $amount === '' ? $cap : Containers::decimal($amount)->toScale(3, RoundingMode::HALF_UP);
            if (! $base->isPositive()) {
                throw new RuntimeException(sprintf('The pieces of "%s" must be more than 0.', $product->name));
            }
            if ($base->isGreaterThan($cap)) {
                throw new RuntimeException(sprintf(
                    '%s: %s pieces is more than %s %s hold (%s). The pieces may be lowered, never raised — add packs instead.',
                    $product->name,
                    Containers::trim((string) $base),
                    Containers::trim((string) $pieces),
                    $pack->name,
                    Containers::trim((string) $cap),
                ));
            }
            $label = mb_substr(Packs::displayName($pack, Packs::of($product)), 0, 80);
            $leaves = [];
            $container = null;
        } else {
            throw new RuntimeException('A purchase line needs an item.');
        }

        // The split, in pieces → that share of the line's amount.
        $split = [];
        $sumPieces = BigDecimal::zero();
        $sum = BigDecimal::zero();
        $scale = $ingredient !== null ? StockDecimal::QUANTITY_SCALE : 3;
        // Fix order B-2 — with a lowered inner count (23 bottles of 2 crates),
        // each branch share gets whole crates of leaves while they last (the
        // missing bottle stays with the last share or the warehouse), and its
        // amount follows those leaves.
        $lowered = $ingredient !== null && isset($rows[0]['leaf_pieces']) && $rows[0]['leaf_pieces'] instanceof BigDecimal;
        $remaining = $leaves;
        $leafTotal = $lowered ? $rows[0]['leaf_pieces'] : null;
        $perPiece = [];
        if ($lowered) {
            [, $per] = Containers::leaf($rows[0]['container'], Containers::of($ingredient));
            foreach (array_keys($leaves) as $leafId) {
                $perPiece[$leafId] = Containers::decimal((string) $per);
            }
        }
        foreach ($allocations as $allocation) {
            $share = Containers::decimal($allocation['pieces']);
            if (! $share->isPositive()) {
                throw new RuntimeException('Each branch share needs more than 0 pieces.');
            }
            $sumPieces = $sumPieces->plus($share);
            $shareLeaves = [];
            foreach ($leaves as $leafId => $leafPieces) {
                if ($lowered) {
                    $want = $share->multipliedBy($perPiece[$leafId]);
                    $have = $remaining[$leafId] ?? BigDecimal::zero();
                    $shareLeaves[$leafId] = $want->isGreaterThan($have) ? $have : $want;
                    $remaining[$leafId] = $have->minus($shareLeaves[$leafId]);
                } else {
                    $shareLeaves[$leafId] = $leafPieces->multipliedBy($share)->dividedBy($pieces, StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
                }
            }
            if ($lowered && $leafTotal !== null && $leafTotal->isPositive()) {
                $shareLeafSum = BigDecimal::zero();
                foreach ($shareLeaves as $leafShare) {
                    $shareLeafSum = $shareLeafSum->plus($leafShare);
                }
                $quantity = $base->multipliedBy($shareLeafSum)->dividedBy($leafTotal, $scale, RoundingMode::HALF_UP);
            } else {
                $quantity = $base->multipliedBy($share)->dividedBy($pieces, $scale, RoundingMode::HALF_UP);
            }
            $sum = $sum->plus($quantity);
            $split[] = ['branch' => $allocation['branch'], 'quantity' => $quantity, 'leaves' => $shareLeaves, 'pieces' => (string) $share];
        }
        if ($sumPieces->isGreaterThan($pieces)) {
            throw new RuntimeException('The branch split is more than the pieces received on this line.');
        }
        $excess = $sum->minus($base);
        if ($excess->isPositive() && $split !== []) {
            $largest = 0;
            foreach ($split as $i => $share) {
                if ($share['quantity']->isGreaterThan($split[$largest]['quantity'])) {
                    $largest = $i;
                }
            }
            $split[$largest]['quantity'] = $split[$largest]['quantity']->minus($excess);
        }

        $lineCost = Containers::decimal($row['line_cost'] ?? '0')->toScale(3, RoundingMode::HALF_UP);
        if ($lineCost->isNegative()) {
            throw new RuntimeException('The price paid cannot be negative.');
        }

        return [
            'quantity' => $ingredient !== null ? (string) StockDecimal::quantity((string) $base) : (string) $base,
            'allocations' => array_map(static fn (array $share): array => [
                'branch' => $share['branch'],
                'quantity' => $ingredient !== null ? (string) StockDecimal::quantity((string) $share['quantity']) : (string) $share['quantity'],
                'leaves' => $share['leaves'],
                'pieces' => $share['pieces'],
            ], $split),
            'line_cost' => (string) $lineCost,
            'pieces' => (string) $pieces,
            'container' => $container,
            'pack' => $pack,
            'container_label' => $label,
            'container_factor' => (string) $factor->toScale(4, RoundingMode::HALF_UP),
            'leaves' => $leaves,
        ];
    }
}
