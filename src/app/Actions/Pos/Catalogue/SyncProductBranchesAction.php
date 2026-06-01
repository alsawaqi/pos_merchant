<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replace a product's per-branch availability + unit stock (idempotent).
 *
 * The caller passes the COMPLETE desired set of branch rows; we upsert each
 * and delete any branch no longer present. An EMPTY set leaves the product
 * with no rows = "available at every branch" (the backward-compatible
 * default the device config relies on).
 *
 * Cross-tenant defence: every branch_id must belong to the actor's company,
 * else the whole sync aborts (RuntimeException -> 422). stock_qty NULL = not
 * unit-tracked at that branch.
 *
 * Audit event: catalogue.product.branches_synced.
 */
final readonly class SyncProductBranchesAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<int, array{branch_id: int|string, is_available: bool, stock_qty?: float|int|string|null}>  $branches
     * @return array<int, BranchProduct>
     */
    public function handle(Product $product, array $branches, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }

        $branchIds = array_values(array_unique(array_map(
            static fn (array $b): int => (int) $b['branch_id'],
            $branches,
        )));

        if ($branchIds !== []) {
            $owned = Branch::query()->where('company_id', $companyId)->whereIn('id', $branchIds)->pluck('id')->all();
            if (count($owned) !== count($branchIds)) {
                throw new RuntimeException('One or more branches in the payload do not belong to your company.');
            }
        }

        return DB::transaction(function () use ($product, $branches, $branchIds, $actor, $companyId): array {
            $before = $this->snapshot($product->id);

            foreach ($branches as $b) {
                $stockQty = array_key_exists('stock_qty', $b) && $b['stock_qty'] !== null && $b['stock_qty'] !== ''
                    ? (float) $b['stock_qty']
                    : null;

                BranchProduct::updateOrCreate(
                    ['branch_id' => (int) $b['branch_id'], 'product_id' => $product->id],
                    ['is_available' => (bool) $b['is_available'], 'stock_qty' => $stockQty],
                );
            }

            BranchProduct::query()
                ->where('product_id', $product->id)
                ->when($branchIds !== [], fn ($q) => $q->whereNotIn('branch_id', $branchIds))
                ->delete();

            $after = $this->snapshot($product->id);

            if ($before !== $after) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.product.branches_synced',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Product::class,
                    auditableId: $product->id,
                    oldValues: ['branches' => $before],
                    newValues: ['branches' => $after],
                ));
            }

            return BranchProduct::query()->where('product_id', $product->id)->orderBy('branch_id')->get()->all();
        });
    }

    /**
     * @return array<int, array{branch_id: int, is_available: bool, stock_qty: float|null}>
     */
    private function snapshot(int $productId): array
    {
        return BranchProduct::query()
            ->where('product_id', $productId)
            ->orderBy('branch_id')
            ->get()
            ->map(static fn (BranchProduct $bp): array => [
                'branch_id' => (int) $bp->branch_id,
                'is_available' => (bool) $bp->is_available,
                'stock_qty' => $bp->stock_qty !== null ? (float) $bp->stock_qty : null,
            ])
            ->all();
    }
}
