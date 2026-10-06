<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ComboSlot;
use App\Models\ComboSlotOption;
use App\Models\Product;
use App\Models\User;
use App\Support\Inventory\PackagingUsage;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

        // LAUNCH-P4 B2 — an item offered inside a combo cannot go while the
        // combo still offers it (the option row's product FK is RESTRICT,
        // and a combo slot must never point at a deleted item).
        $combos = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', ComboSlot::query()
                ->whereIn('id', ComboSlotOption::query()->where('product_id', $product->id)->select('slot_id'))
                ->select('combo_product_id'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
        if ($combos !== []) {
            throw new RuntimeException('This item is offered in a combo: remove it from '.implode(', ', $combos).' first.');
        }

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
            \App\Support\Inventory\ItemCodes::forgetBarcodes($companyId, ['product_id' => (int) $productId]);

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
