<?php

declare(strict_types=1);

namespace App\Actions\Pos\Offers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Offer;
use App\Models\User;
use App\Support\MerchantTenantContext;
use App\Support\OfferConfig;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-F9 — create an offer / promotion rule.
 *
 * The form request already validated the shared fields AND the strict
 * per-type config shape + tenant ownership ({@see OfferConfig::errors});
 * this action enforces the remaining business invariants:
 *
 *   - name non-empty
 *   - validity_end > validity_start (when both set)
 *   - bundle is ALWAYS cashier-picked → auto_apply forced FALSE;
 *     every other type defaults to TRUE (self-applying) unless the
 *     merchant turned it off
 *
 * The persisted config is the NORMALIZED canonical shape (ints cast,
 * unknown keys dropped) so the device-config slice emits it verbatim.
 *
 * Audit event: catalogue.offer.created.
 */
final readonly class CreateOfferAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): Offer
    {
        $companyId = $this->tenant->requiredId();

        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Offer name is required.');
        }

        $type = OfferType::from((string) $attributes['type']);
        $config = OfferConfig::normalize($type, (array) $attributes['config']);

        $start = $attributes['validity_start'] ?? null;
        $end = $attributes['validity_end'] ?? null;
        if ($start !== null && $end !== null && $end <= $start) {
            throw new RuntimeException('validity_end must be after validity_start.');
        }

        // Bundle offers can never self-apply — the cashier composes the
        // deal at the POS. The other four types default to auto-applying.
        $autoApply = $type === OfferType::Bundle
            ? false
            : (bool) ($attributes['auto_apply'] ?? true);

        return DB::transaction(function () use ($attributes, $name, $type, $config, $start, $end, $autoApply, $actor, $companyId): Offer {
            /** @var Offer $offer */
            $offer = Offer::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'name_ar' => isset($attributes['name_ar']) && trim((string) $attributes['name_ar']) !== ''
                    ? trim((string) $attributes['name_ar'])
                    : null,
                'type' => $type->value,
                'config' => $config,
                'auto_apply' => $autoApply,
                'validity_start' => $start,
                'validity_end' => $end,
                'dayofweek_mask' => $attributes['dayofweek_mask'] ?? null,
                'time_start' => $attributes['time_start'] ?? null,
                'time_end' => $attributes['time_end'] ?? null,
                'branch_scope_json' => $attributes['branch_scope_json'] ?? null,
                'max_per_order' => $attributes['max_per_order'] ?? null,
                'status' => $attributes['status'] ?? OfferStatus::Active->value,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.offer.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Offer::class,
                auditableId: $offer->id,
                newValues: [
                    'name' => $name,
                    'type' => $type->value,
                    'auto_apply' => $autoApply,
                ],
            ));

            return $offer->fresh();
        });
    }
}
