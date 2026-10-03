<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductSoldOut;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P4 B4 — switch a product sold out (or back on sale) at one branch
 * (owner decision 4): a manual switch, per branch, on every channel, that
 * stays until switched back. Never driven by stock.
 *
 * A pos_product_sold_out row present = sold out there; back on sale deletes
 * it. The product's updated_at is touched so the device config re-emits the
 * product (its sold_out flag) on the next delta. Idempotent: no change, no
 * write and no audit.
 *
 * Audit events: catalogue.product.sold_out / catalogue.product.back_on_sale
 * (with the branch).
 */
final readonly class SetProductSoldOutAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Product $product, Branch $branch, bool $soldOut, User $actor): bool
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId || (int) $branch->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($product, $branch, $soldOut, $actor, $companyId): bool {
            $row = ProductSoldOut::query()
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($soldOut === ($row !== null)) {
                return $soldOut;
            }

            if ($soldOut) {
                $row = ProductSoldOut::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'set_by_user_id' => $actor->getKey(),
                    'set_at' => now(),
                ]);
            } else {
                $row->delete();
            }

            $product->touch();

            $this->writeAuditLog->handle(new AuditLogData(
                event: $soldOut ? 'catalogue.product.sold_out' : 'catalogue.product.back_on_sale',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: (int) $branch->id,
                auditableType: Product::class,
                auditableId: $product->id,
                oldValues: ['sold_out' => ! $soldOut],
                newValues: ['sold_out' => $soldOut],
                metadata: ['branch_id' => (int) $branch->id, 'branch_name' => (string) $branch->name],
            ));

            return $soldOut;
        });
    }
}
