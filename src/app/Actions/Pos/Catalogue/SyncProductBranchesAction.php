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
use InvalidArgumentException;
use RuntimeException;

/**
 * Set which branches sell a product (LAUNCH-P4 H6 + H7).
 *
 * The branch rule lives on the product (pos_products.branch_scope):
 *   'all'      — every branch sells it, except a branch whose
 *                pos_branch_product row has is_available = false;
 *   'selected' — only branches whose row has is_available = true.
 * Stock rows never restrict: a shelf count at one branch no longer hides the
 * product at the others.
 *
 * This action:
 *   - 'all'      → stores the rule and switches every existing row back to
 *                  available (the editor offers no per-branch exceptions);
 *   - 'selected' → stores the rule, marks the chosen branches available
 *                  (creating a row with NO shelf count where none exists)
 *                  and every other existing row unavailable.
 * It NEVER writes stock_qty and NEVER deletes a row: shelf counts change only
 * through the stock actions, which write ledger movements (H7 — the old form
 * re-sent the counts it loaded and overwrote live ones, and refused a save
 * below zero).
 *
 * Cross-tenant defence: every branch id must belong to the actor's company.
 * Audit event: catalogue.product.branches_synced (only when something
 * changed).
 */
final readonly class SyncProductBranchesAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  list<int|string>  $branchIds  the chosen branches ('selected'); ignored for 'all'
     * @return array<int, BranchProduct>
     */
    public function handle(Product $product, string $scope, array $branchIds, User $actor): array
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }
        if (! in_array($scope, [Product::SCOPE_ALL, Product::SCOPE_SELECTED], true)) {
            throw new InvalidArgumentException('Unknown branch scope.');
        }

        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        if ($scope === Product::SCOPE_ALL) {
            $branchIds = [];
        }
        if ($branchIds !== []) {
            $owned = Branch::query()->where('company_id', $companyId)->whereIn('id', $branchIds)->pluck('id')->all();
            if (count($owned) !== count($branchIds)) {
                throw new RuntimeException('One or more branches in the payload do not belong to your company.');
            }
        }

        return DB::transaction(function () use ($product, $scope, $branchIds, $actor, $companyId): array {
            $before = $this->snapshot($product);

            if ($product->branch_scope !== $scope) {
                $product->forceFill(['branch_scope' => $scope])->save();
            }

            if ($scope === Product::SCOPE_ALL) {
                BranchProduct::query()
                    ->where('product_id', $product->id)
                    ->where('is_available', false)
                    ->update(['is_available' => true, 'updated_at' => now()]);
            } else {
                foreach ($branchIds as $branchId) {
                    $row = BranchProduct::query()
                        ->where('branch_id', $branchId)
                        ->where('product_id', $product->id)
                        ->first();
                    if ($row === null) {
                        BranchProduct::query()->create([
                            'branch_id' => $branchId,
                            'product_id' => $product->id,
                            'is_available' => true,
                            'stock_qty' => null,
                        ]);
                    } elseif (! $row->is_available) {
                        $row->forceFill(['is_available' => true])->save();
                    }
                }
                BranchProduct::query()
                    ->where('product_id', $product->id)
                    ->when($branchIds !== [], fn ($q) => $q->whereNotIn('branch_id', $branchIds))
                    ->where('is_available', true)
                    ->update(['is_available' => false, 'updated_at' => now()]);
            }

            $after = $this->snapshot($product->fresh());

            if ($before !== $after) {
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.product.branches_synced',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Product::class,
                    auditableId: $product->id,
                    oldValues: $before,
                    newValues: $after,
                ));
            }

            return BranchProduct::query()->where('product_id', $product->id)->orderBy('branch_id')->get()->all();
        });
    }

    /**
     * @return array{branch_scope: string, available_branch_ids: list<int>, unavailable_branch_ids: list<int>}
     */
    private function snapshot(Product $product): array
    {
        $rows = BranchProduct::query()->where('product_id', $product->id)->orderBy('branch_id')->get();

        return [
            'branch_scope' => (string) ($product->branch_scope ?? Product::SCOPE_ALL),
            'available_branch_ids' => $rows->where('is_available', true)->pluck('branch_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'unavailable_branch_ids' => $rows->where('is_available', false)->pluck('branch_id')->map(fn ($id): int => (int) $id)->values()->all(),
        ];
    }
}
