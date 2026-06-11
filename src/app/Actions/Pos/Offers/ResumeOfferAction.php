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
 * P-F9 — resume a paused offer (paused → active). Counterpart of
 * {@see PauseOfferAction}; refuses unless currently paused.
 */
final readonly class ResumeOfferAction
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
        if ($offer->status !== OfferStatus::Paused) {
            throw new RuntimeException(sprintf(
                'Only paused offers can be resumed (current status: %s).',
                $offer->status->value,
            ));
        }

        return DB::transaction(function () use ($offer, $actor, $companyId): Offer {
            $oldStatus = $offer->status->value;
            $offer->forceFill(['status' => OfferStatus::Active->value])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.offer.resumed',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Offer::class,
                auditableId: $offer->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => OfferStatus::Active->value],
            ));

            return $offer->fresh();
        });
    }
}
