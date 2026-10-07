<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalogue\ComboLinesInput;
use App\Support\Inventory\ItemCodes;
use App\Support\Inventory\PackagingUsage;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete a product. Phase 7 orders will reference
 * products by id; soft delete preserves the historical
 * receipt while the merchant retires a discontinued item.
 *
 * Audit event: catalogue.product.deleted with a snapshot
 * including the price-at-delete-time (helps order disputes:
 * "you said this cost 5 OMR but I see it deleted at 7").
 */
final readonly class DeleteProductAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Product $product, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }

        // LAUNCH combo add-on — an item included in a combo or meal, or one of
        // their upgrades, cannot go while they use it (the line's product FK
        // is RESTRICT). A product inside a choice CATEGORY may go: the choice
        // simply no longer offers it.
        ComboLinesInput::refuseProductInUse($product);

        // LAUNCH packaging add-on (fix order PK-B1, M2) — an item on a
        // per-order packaging list is taken with every order of that type:
        // refused from every delete path (catalogue and physical items).
        PackagingUsage::refuse(PackagingUsage::productLists((int) $product->id), (string) $product->name, 'be deleted', 'حذفه');

        DB::transaction(function () use ($product, $actor, $companyId): void {
            $snapshot = [
                'name' => $product->name,
                'category_id' => $product->category_id,
                'sku' => $product->sku,
                'base_price' => (string) $product->base_price,
            ];
            $productId = $product->id;

            // Fix order B-1 (M2) — its scan barcodes go with it, so the code
            // can be linked to another item.
            ItemCodes::forgetBarcodes($companyId, ['product_id' => (int) $productId]);

            $product->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: $productId,
                oldValues: $snapshot,
            ));
        });
    }
}
