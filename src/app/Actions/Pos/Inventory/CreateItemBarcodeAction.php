<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\ItemBarcode;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Support\Inventory\ItemCodes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH review add-on (A5, D3, F2) — remember a barcode: on an ingredient's
 * container (several per container — other brands of the same size), on the
 * ingredient itself, on a physical item (one piece) or on one of its packs.
 * The scan box's "link an unknown barcode" saves through here too.
 *
 * The barcode is kept as a trimmed string (leading zeros stay) and is unique
 * per company across pos_item_barcodes and pos_products.barcode, checked under
 * the per-company code lock. A container / pack must belong to the item.
 *
 * Audit event: inventory.barcode.created.
 */
final readonly class CreateItemBarcodeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(
        int $companyId,
        string $barcode,
        ?Ingredient $ingredient,
        ?IngredientAltUnit $container,
        ?Product $product,
        ?ProductPack $pack,
        ?string $label,
        User $actor,
    ): ItemBarcode {
        $barcode = ItemCodes::normalize($barcode);
        if ($barcode === '') {
            throw new RuntimeException('Scan or type the barcode.');
        }
        if (($ingredient === null) === ($product === null)) {
            throw new RuntimeException('A barcode belongs to one ingredient or one item.');
        }
        if ($ingredient !== null && (int) $ingredient->company_id !== $companyId) {
            throw new RuntimeException('Ingredient does not belong to your company.');
        }
        if ($product !== null && (int) $product->company_id !== $companyId) {
            throw new RuntimeException('Item does not belong to your company.');
        }
        if ($container !== null && ($ingredient === null || (int) $container->ingredient_id !== (int) $ingredient->id)) {
            throw new RuntimeException('That container is not one of this ingredient\'s containers.');
        }
        if ($pack !== null && ($product === null || (int) $pack->product_id !== (int) $product->id)) {
            throw new RuntimeException('That pack is not one of this item\'s packs.');
        }

        return DB::transaction(function () use ($companyId, $barcode, $ingredient, $container, $product, $pack, $label, $actor): ItemBarcode {
            ItemCodes::lockBarcode($companyId);
            // Fix order B-1 (M2) — a code left on a deleted item (older data) is released first.
            ItemCodes::releaseOrphans($companyId, $barcode);
            if (($holder = ItemCodes::barcodeHolder($companyId, $barcode)) !== null) {
                throw new BarcodeTakenException(ItemCodes::barcodeMessage($holder['owner']), $holder['barcode_uuid']);
            }

            /** @var ItemBarcode $row */
            $row = ItemBarcode::query()->create([
                'company_id' => $companyId,
                'barcode' => $barcode,
                'ingredient_id' => $ingredient?->id,
                'container_id' => $container?->id,
                'product_id' => $product?->id,
                'pack_id' => $pack?->id,
                'label' => $label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 80) : null,
                'created_by_user_id' => $actor->getKey(),
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.barcode.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: ItemBarcode::class,
                auditableId: $row->id,
                newValues: [
                    'barcode' => $barcode,
                    'ingredient_id' => $ingredient?->id,
                    'container_id' => $container?->id,
                    'product_id' => $product?->id,
                    'pack_id' => $pack?->id,
                    'label' => $row->label,
                ],
            ));

            return $row;
        });
    }
}
