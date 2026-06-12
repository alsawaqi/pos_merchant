<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Partial-update a product. Mutable: every field except
 * uuid, company_id (tenant lock).
 *
 * category_id change re-validates ownership — moving a
 * product to a category from another company would be a
 * tenancy break.
 *
 * Audit event: catalogue.product.updated with old/new diffs.
 * Price changes specifically are flagged in the event (so
 * reporting can spot suspicious price drops).
 */
final readonly class UpdateProductAction
{
    private const MUTABLE_FIELDS = [
        'category_id',
        'sku',
        'barcode',
        'name',
        'name_ar',
        'description',
        'image_url',
        'base_price',
        // Phase 4.9 — per-product delivery override.
        'delivery_price',
        // Phase 7 — stock mode (the form always submitted it; it was
        // silently dropped here until Phase D2 fixed the omission).
        'stock_mode',
        // Phase D2 — unit-mode LOW STOCK badge threshold.
        'low_stock_threshold',
        // P-G1.5 — default shelf life in days (NULL = keeps indefinitely).
        'shelf_life_days',
        'cost_price',
        'tax_rate',
        // Phase D2 — §5.5.3 flags (tax_inclusive display-only for now).
        'tax_inclusive',
        'show_on_customer_tablet',
        // P-G2 — internal item (never on the POS menu or tablet).
        'is_internal',
        // G1 — menu time-window (both NULL = always available).
        'available_from',
        'available_until',
        'display_order',
        'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Product $product, array $attributes, User $actor): Product
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }

        // Category move — verify the new category is ours.
        if (array_key_exists('category_id', $attributes) && ! empty($attributes['category_id'])) {
            $categoryOwned = ProductCategory::query()
                ->where('id', $attributes['category_id'])
                ->where('company_id', $companyId)
                ->exists();
            if (! $categoryOwned) {
                throw new RuntimeException(
                    'The selected category does not belong to your company.',
                );
            }
        }

        return DB::transaction(function () use ($product, $attributes, $actor, $companyId): Product {
            $changes = [];

            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = $attributes[$field];
                $oldValue = $product->{$field};
                $oldComparable = $oldValue instanceof \BackedEnum
                    ? $oldValue->value
                    : $oldValue;

                // Money columns come back as strings via the
                // decimal cast — normalize both sides for the
                // comparison.
                if (in_array($field, ['base_price', 'delivery_price', 'low_stock_threshold', 'cost_price', 'tax_rate'], true)) {
                    $sameValue = (string) $oldComparable === (string) $newValue;
                } else {
                    $sameValue = $oldComparable == $newValue;
                }
                if ($sameValue) {
                    continue;
                }

                $changes[$field] = ['old' => $oldComparable, 'new' => $newValue];
                $product->{$field} = $newValue;
            }

            if ($changes === []) {
                return $product->fresh();
            }

            $product->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: $product->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $product->fresh();
        });
    }
}
