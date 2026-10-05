<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Pos\DeliveryProviders\SetProductDeliveryPriceAction;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ComboSlot;
use App\Models\ComboSlotOption;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalogue\MenuExtras;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH-P4 B2 — create or update a combo in ONE transaction (owner decision
 * 7): the combo product (product_type 'combo', stock_mode 'untracked', no
 * recipe, no components) with its channels, branch rule and per-provider
 * rows, plus its choice slots and the items each slot offers.
 *
 * Slots keep their ids across edits (a slot sent with its id is updated, a
 * new one created, a missing one deleted with its options): devices and order
 * lines refer to slots by id (pos_order_items.combo_slot_id is a snapshot).
 * Options are matched per slot by product (UNIQUE (slot_id, product_id)).
 *
 * The product rows go through CreateProductAction / UpdateProductAction
 * (their audit rows); the slots are audited as catalogue.combo.slots_saved
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

            $before = $this->slotSnapshot($combo);
            $this->saveSlots($combo, (array) $data['slots'], $companyId);
            $after = $this->slotSnapshot($combo);
            if ($before !== $after) {
                // The device config delta re-sends a product by its
                // updated_at: a slot or option change (deletes included)
                // must move it, or devices only catch up on a full sync.
                $combo->touch();
                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'catalogue.combo.slots_saved',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Product::class,
                    auditableId: $combo->id,
                    oldValues: ['slots' => $before],
                    newValues: ['slots' => $after],
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

    /**
     * @param  list<array<string, mixed>>  $slots
     */
    private function saveSlots(Product $combo, array $slots, int $companyId): void
    {
        $existing = ComboSlot::query()->where('combo_product_id', $combo->id)->get()->keyBy('id');
        $keep = [];

        // LAUNCH review add-on — one main per combo (a partial unique index):
        // clear the main flag of every saved slot that is no longer the main
        // BEFORE another slot takes it.
        // A payload without any is_main key (an older open tab) keeps the
        // saved main.
        $sendsMain = false;
        $mainIds = [];
        foreach ($slots as $slotData) {
            $sendsMain = $sendsMain || array_key_exists('is_main', $slotData);
            if (! empty($slotData['is_main']) && isset($slotData['id'])) {
                $mainIds[] = (int) $slotData['id'];
            }
        }
        foreach ($existing as $slot) {
            if ($sendsMain && $slot->is_main && ! in_array((int) $slot->id, $mainIds, true)) {
                $slot->forceFill(['is_main' => false])->save();
            }
        }

        foreach (array_values($slots) as $sort => $slotData) {
            $attributes = [
                'name' => trim((string) $slotData['name']),
                'name_ar' => isset($slotData['name_ar']) && trim((string) $slotData['name_ar']) !== '' ? trim((string) $slotData['name_ar']) : null,
                'min_choices' => (int) $slotData['min_choices'],
                'max_choices' => (int) $slotData['max_choices'],
                'sort_order' => $sort,
            ];
            if ($sendsMain) {
                $attributes['is_main'] = ! empty($slotData['is_main']);
            }
            $id = isset($slotData['id']) ? (int) $slotData['id'] : null;
            $slot = $id !== null ? $existing->get($id) : null;
            if ($slot === null) {
                $slot = ComboSlot::query()->create($attributes + [
                    'company_id' => $companyId,
                    'combo_product_id' => $combo->id,
                ]);
            } else {
                $slot->forceFill($attributes)->save();
            }
            $keep[] = (int) $slot->id;

            $this->saveOptions($slot, (array) $slotData['options'], $companyId);
        }

        ComboSlot::query()
            ->where('combo_product_id', $combo->id)
            ->whereNotIn('id', $keep === [] ? [0] : $keep)
            ->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function saveOptions(ComboSlot $slot, array $options, int $companyId): void
    {
        $productIds = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', array_map(static fn (array $o): string => (string) $o['product_uuid'], $options))
            ->pluck('id', 'uuid');

        $keep = [];
        foreach (array_values($options) as $sort => $option) {
            $productId = (int) ($productIds[(string) $option['product_uuid']] ?? 0);
            if ($productId === 0) {
                throw new RuntimeException('A combo item does not belong to your company.');
            }
            $keep[] = $productId;
            $extra = number_format((float) ($option['extra_price'] ?? 0), 3, '.', '');

            $row = ComboSlotOption::query()->where('slot_id', $slot->id)->where('product_id', $productId)->first();
            $attributes = ['extra_price' => $extra, 'is_default' => (bool) ($option['is_default'] ?? false), 'sort_order' => $sort];
            if ($row === null) {
                ComboSlotOption::query()->create($attributes + [
                    'company_id' => $companyId,
                    'slot_id' => $slot->id,
                    'product_id' => $productId,
                ]);
            } else {
                $row->forceFill($attributes)->save();
            }
        }

        ComboSlotOption::query()->where('slot_id', $slot->id)->whereNotIn('product_id', $keep)->delete();
    }

    /**
     * @return list<array{id: int, name: string, name_ar: string|null, min: int, max: int, options: list<array{product_id: int, extra_price: string, is_default: bool}>}>
     */
    private function slotSnapshot(Product $combo): array
    {
        return ComboSlot::query()
            ->where('combo_product_id', $combo->id)
            ->with('options')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (ComboSlot $slot): array => [
                'id' => (int) $slot->id,
                'name' => (string) $slot->name,
                'name_ar' => $slot->name_ar,
                'min' => (int) $slot->min_choices,
                'max' => (int) $slot->max_choices,
                // LAUNCH review add-on — a main change audits and moves the
                // combo's updated_at (devices re-read it by delta).
                'is_main' => (bool) $slot->is_main,
                'options' => $slot->options->map(static fn (ComboSlotOption $o): array => [
                    'product_id' => (int) $o->product_id,
                    'extra_price' => (string) $o->extra_price,
                    'is_default' => (bool) $o->is_default,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
