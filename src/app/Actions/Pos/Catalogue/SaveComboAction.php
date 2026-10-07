<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Pos\DeliveryProviders\SetProductDeliveryPriceAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalogue\ComboLinesInput;
use App\Support\Catalogue\MenuExtras;
use App\Support\Inventory\ItemCodes;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH-P4 B2 — create or update a combo in ONE transaction (owner decision
 * 7): the combo product (product_type 'combo', stock_mode 'untracked', no
 * recipe, no components) with its channels, branch rule and per-provider
 * rows, plus its LINES (LAUNCH combo add-on, {@see ComboLinesInput}):
 * included items with their upgrades, and choices from a category.
 *
 * Lines keep their ids across edits (a line sent with its id is updated, a
 * new one created, a missing one deleted with its upgrades and overrides):
 * devices and order lines refer to lines by id (pos_order_items.combo_line_id
 * is a snapshot).
 *
 * The product rows go through CreateProductAction / UpdateProductAction
 * (their audit rows); the lines are audited as catalogue.combo.lines_saved
 * with the before/after shape when they change.
 */
final readonly class SaveComboAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
        private WriteAuditLogAction $writeAuditLog,
        private CreateProductAction $createProduct,
        private UpdateProductAction $updateProduct,
        private SyncProductBranchesAction $syncBranches,
        private SetProductDeliveryPriceAction $setDeliveryPrice,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated SaveComboRequest data
     */
    public function handle(?Product $combo, array $data, User $actor): Product
    {
        $companyId = $this->tenant->requiredId();
        if ($combo !== null && ((int) $combo->company_id !== $companyId || ! $combo->isCombo())) {
            abort(404);
        }

        return DB::transaction(function () use ($combo, $data, $actor, $companyId): Product {
            // LAUNCH review fix order B-1 (L5) — the SKU / barcode are claimed
            // under the per-company locks and checked again across tables.
            ItemCodes::claimProductCodes($companyId, $data['sku'] ?? null, $data['barcode'] ?? null, $combo?->id !== null ? (int) $combo->id : null);

            $fields = [
                'name' => $data['name'],
                'name_ar' => $data['name_ar'] ?? null,
                'description' => $data['description'] ?? null,
                'description_ar' => $data['description_ar'] ?? null,
                'image_url' => $data['image_url'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'sku' => $data['sku'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'base_price' => $data['base_price'],
                'delivery_price' => $data['delivery_price'] ?? null,
                'sold_in_store' => (bool) $data['sold_in_store'],
                'show_on_customer_tablet' => (bool) $data['show_on_customer_tablet'],
                'sold_on_delivery' => (bool) $data['sold_on_delivery'],
                'available_from' => $data['available_from'] ?? null,
                'available_until' => $data['available_until'] ?? null,
            ];
            if (array_key_exists('display_order', $data) && $data['display_order'] !== null) {
                $fields['display_order'] = (int) $data['display_order'];
            }
            // LAUNCH review add-on — limited-time dates and the combo's own
            // cooking time; a payload without the key (an older open tab)
            // keeps what is saved.
            foreach (['on_sale_from', 'on_sale_until', 'cooking_minutes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[$field] = $field === 'cooking_minutes' ? MenuExtras::minutes($data[$field]) : MenuExtras::day($data[$field]);
                }
            }

            if ($combo === null) {
                $combo = $this->createProduct->handle($fields + [
                    'product_type' => Product::TYPE_COMBO,
                    'stock_mode' => 'untracked',
                ], $actor);
            } else {
                if (array_key_exists('status', $data)) {
                    $fields['status'] = $data['status'];
                }
                $combo = $this->updateProduct->handle($combo, $fields, $actor);
            }

            $owner = ['combo_product_id' => (int) $combo->id];
            $before = ComboLinesInput::present($owner);
            ComboLinesInput::save($owner, (array) $data['lines'], $companyId);
            $after = ComboLinesInput::present($owner);
            if ($before !== $after) {
                // The combo row moves too (its updated_at shows the change).
                $combo->touch();
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.combo.lines_saved',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Product::class,
                    auditableId: $combo->id,
                    oldValues: ['lines' => $before],
                    newValues: ['lines' => $after],
                ));
            }

            if (array_key_exists('branches', $data) && $data['branches'] !== null) {
                $this->syncBranches->handle(
                    $combo,
                    (string) ($data['branches']['branch_scope'] ?? Product::SCOPE_ALL),
                    $data['branches']['branch_ids'] ?? [],
                    $actor,
                );
            }

            foreach ($data['delivery_prices'] ?? [] as $row) {
                $provider = DeliveryProvider::query()
                    ->where('company_id', $companyId)
                    ->where('uuid', (string) $row['provider_uuid'])
                    ->first();
                if ($provider === null) {
                    throw new RuntimeException('A delivery provider in the list does not belong to your company.');
                }
                $this->setDeliveryPrice->handle($combo, $provider, $row['price'] ?? null, $actor, (bool) ($row['listed'] ?? true));
            }

            return $combo->fresh();
        });
    }
}
