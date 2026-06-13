<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 — receive a bulk quantity of a finished good AND split it across
 * branches in ONE step ("80 in: 50 to A, 30 to B"). Composes Receive (credit the
 * central pool) + Allocate (fan out to branches) inside a single transaction, so
 * the whole receive-and-distribute lands together or not at all. Anything not
 * distributed stays in the central pool.
 *
 * Guard: the distributed total may not exceed the received quantity. We check it
 * up front (pure arithmetic on the inputs) before touching the DB, so an
 * over-distribution writes nothing. The inner Allocate still enforces its own
 * "<= central balance" guard under a row lock, which holds trivially here since
 * the receive credits exactly `total` first.
 */
final readonly class ReceiveAndDistributeProductStockAction
{
    public function __construct(
        private ReceiveProductStockAction $receive,
        private AllocateProductStockAction $allocate,
    ) {}

    /**
     * @param  list<array{branch: Branch, quantity: string|float|int}>  $lines
     * @return array{received: ProductStockMovement, allocations: list<ProductStockMovement>}
     */
    public function handle(
        Product $product,
        string|float|int $total,
        array $lines,
        ?string $note,
        User $actor,
        string|float|int|null $totalCost = null,
        string|float|int|null $deliveryCost = null,
    ): array {
        $totalQty = (float) $total;
        if ($totalQty <= 0) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }

        $distributed = 0.0;
        foreach ($lines as $line) {
            $distributed += (float) $line['quantity'];
        }
        if ($distributed > $totalQty + 1e-9) {
            throw new RuntimeException(sprintf(
                'You are distributing %.3f but only received %.3f. Distribute at most the received total.',
                $distributed,
                $totalQty,
            ));
        }

        return DB::transaction(function () use ($product, $total, $lines, $note, $actor, $totalCost, $deliveryCost): array {
            // PD2/PD5 — the purchase cost + delivery belong to the RECEIVE leg
            // (one expense pair for the whole delivery; the split changes
            // nothing about the money).
            $received = $this->receive->handle($product, $total, $note, $actor, $totalCost, $deliveryCost);
            $allocations = $lines === []
                ? []
                : $this->allocate->handle($product, $lines, $note, $actor);

            return ['received' => $received, 'allocations' => $allocations];
        });
    }
}
