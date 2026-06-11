<?php

declare(strict_types=1);

namespace App\Actions\Pos\Offers;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-F9 — pause an offer (active → paused). Dedicated Action so the
 * audit event reads catalogue.offer.paused (the discount-lifecycle
 * convention). Refuses if not active — a silent no-op would mask a
 * UI race.
 */
final readonly class PauseOfferAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Offer $offer, User $actor): Offer
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $offer->company_id !== $companyId) {
            abort(404);
        }
        if ($offer->status !== OfferStatus::Active) {
            throw new RuntimeException(sprintf(
                'Only active offers can be paused (current status: %s).',
                $offer->status->value,
            ));
        }

        return DB::transaction(function () use ($offer, $actor, $companyId): Offer {
            $oldStatus = $offer->status->value;
            $offer->forceFill(['status' => OfferStatus::Paused->value])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.offer.paused',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Offer::class,
                auditableId: $offer->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => OfferStatus::Paused->value],
            ));

            return $offer->fresh();
        });
    }
}
