<?php

declare(strict_types=1);

namespace App\Actions\Pos\Offers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Offer;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * P-F9 — soft-delete an offer.
 *
 * Historical pos_order_discounts rows keep their offer_id + name
 * snapshot (the FK only nulls on a HARD delete), so the by-offer
 * report still reads correctly. The id surfaces in the device config
 * delta `deleted.offers` purge list so terminals drop it.
 */
final readonly class DeleteOfferAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Offer $offer, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $offer->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($offer, $actor, $companyId): void {
            $offerId = $offer->id;
            $snapshot = [
                'name' => $offer->name,
                'type' => $offer->type?->value,
            ];

            $offer->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.offer.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Offer::class,
                auditableId: $offerId,
                oldValues: $snapshot,
            ));
        });
    }
}
