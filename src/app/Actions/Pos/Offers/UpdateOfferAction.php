<?php

declare(strict_types=1);

namespace App\Actions\Pos\Offers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\OfferType;
use App\Models\Offer;
use App\Models\User;
use App\Support\MerchantTenantContext;
use App\Support\OfferConfig;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-F9 — partial-update an offer with diff-aware audit. Same pattern
 * as UpdateDiscountAction.
 *
 * Invariants:
 *   - validity_end > validity_start (current row overlaid with payload)
 *   - the EFFECTIVE type (payload type ?? current) being bundle forces
 *     auto_apply FALSE whatever the client sent — bundles are always
 *     cashier-picked (mirror of the discount product-scope forcing)
 *   - a supplied config is normalized to the canonical per-type shape
 *     (the form request already validated it against the effective type)
 *
 * Idempotent: same shape in → no audit row written.
 */
final readonly class UpdateOfferAction
{
    private const MUTABLE_FIELDS = [
        'name', 'name_ar', 'type', 'config', 'auto_apply',
        'validity_start', 'validity_end',
        'dayofweek_mask', 'time_start', 'time_end',
        'branch_scope_json', 'max_per_order', 'status',
    ];

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Offer $offer, array $attributes, User $actor): Offer
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $offer->company_id !== $companyId) {
            abort(404);
        }

        if (array_key_exists('name', $attributes)) {
            $name = trim((string) $attributes['name']);
            if ($name === '') {
                throw new RuntimeException('Offer name is required.');
            }
            $attributes['name'] = $name;
        }

        // Validate the combined validity window using current values
        // overlaid with the payload.
        $start = array_key_exists('validity_start', $attributes)
            ? $attributes['validity_start']
            : $offer->validity_start;
        $end = array_key_exists('validity_end', $attributes)
            ? $attributes['validity_end']
            : $offer->validity_end;
        if ($start !== null && $end !== null && $end <= $start) {
            throw new RuntimeException('validity_end must be after validity_start.');
        }

        $effectiveType = array_key_exists('type', $attributes)
            ? OfferType::from((string) $attributes['type'])
            : $offer->type;

        // Bundle offers can never self-apply — covers both "toggle on for
        // a bundle" (ignored) and "re-type an offer to bundle" (flips off).
        if ($effectiveType === OfferType::Bundle) {
            $attributes['auto_apply'] = false;
        }

        if (array_key_exists('config', $attributes)) {
            $attributes['config'] = OfferConfig::normalize($effectiveType, (array) $attributes['config']);
        }

        return DB::transaction(function () use ($offer, $attributes, $actor, $companyId): Offer {
            $changes = [];
            foreach (self::MUTABLE_FIELDS as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }
                $newValue = match ($field) {
                    'auto_apply' => (bool) $attributes[$field],
                    'dayofweek_mask', 'max_per_order' => $attributes[$field] === null ? null : (int) $attributes[$field],
                    default => $attributes[$field],
                };

                $current = $offer->{$field};
                // Cast enum + datetime to comparable form.
                $currentScalar = match (true) {
                    $current instanceof \BackedEnum => $current->value,
                    $current instanceof \DateTimeInterface => $current->format('Y-m-d H:i:s'),
                    is_bool($current) => $current,
                    default => $current,
                };
                $newScalar = match (true) {
                    $newValue instanceof \BackedEnum => $newValue->value,
                    $newValue instanceof \DateTimeInterface => $newValue->format('Y-m-d H:i:s'),
                    default => $newValue,
                };
                if ($currentScalar == $newScalar) {
                    continue;
                }
                $changes[$field] = ['old' => $currentScalar, 'new' => $newScalar];
                $offer->{$field} = $newValue;
            }

            if ($changes === []) {
                return $offer->fresh();
            }

            $offer->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.offer.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Offer::class,
                auditableId: $offer->id,
                oldValues: array_map(static fn (array $v): mixed => $v['old'], $changes),
                newValues: array_map(static fn (array $v): mixed => $v['new'], $changes),
            ));

            return $offer->fresh();
        });
    }
}
