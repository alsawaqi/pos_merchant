<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\LoyaltyShortfallReview;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Resolve one flagged shortfall without mutating the loyalty ledger row. */
final readonly class ResolveLoyaltyShortfallReviewAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(LoyaltyTransaction $marker, User $actor, string $resolutionNote): LoyaltyShortfallReview
    {
        if ((int) $marker->company_id !== (int) $actor->company_id) {
            abort(404);
        }

        return DB::transaction(function () use ($marker, $actor, $resolutionNote): LoyaltyShortfallReview {
            /** @var LoyaltyTransaction $locked */
            $locked = LoyaltyTransaction::query()->lockForUpdate()->findOrFail($marker->id);
            if (! $locked->isShortfallReviewMarker()) {
                throw new RuntimeException('Loyalty transaction is not a reviewable shortfall marker.');
            }

            $existing = LoyaltyShortfallReview::query()
                ->where('loyalty_transaction_id', $locked->id)
                ->first();
            if ($existing !== null) {
                return $existing->load('resolvedBy');
            }

            $resolvedAt = now();
            $review = LoyaltyShortfallReview::query()->create([
                'company_id' => $locked->company_id,
                'loyalty_transaction_id' => $locked->id,
                'resolved_by_user_id' => $actor->id,
                'resolution_note' => trim($resolutionNote),
                'resolved_at' => $resolvedAt,
                'created_at' => $resolvedAt,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'loyalty.shortfall.resolved',
                actorUserId: (int) $actor->id,
                companyId: (int) $locked->company_id,
                auditableType: LoyaltyShortfallReview::class,
                auditableId: (int) $review->id,
                oldValues: ['status' => 'pending'],
                newValues: [
                    'status' => 'resolved',
                    'loyalty_transaction_id' => (int) $locked->id,
                    'resolution_note' => $review->resolution_note,
                    'resolved_at' => $review->resolved_at?->toIso8601String(),
                ],
            ));

            return $review->load('resolvedBy');
        });
    }
}
