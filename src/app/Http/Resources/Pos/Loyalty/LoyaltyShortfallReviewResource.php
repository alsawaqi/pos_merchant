<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Loyalty;

use App\Models\LoyaltyTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LoyaltyTransaction */
class LoyaltyShortfallReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $review = $this->shortfallReview;
        $account = $this->account;
        $amounts = $this->shortfallAmounts();

        return [
            'transaction_id' => (int) $this->id,
            'transaction_uuid' => $this->uuid,
            'status' => $review === null ? 'pending' : 'resolved',
            'reason' => $this->reason,
            'requested' => $amounts['requested'] ?? null,
            'applied' => $amounts['applied'] ?? null,
            'shortfall' => $amounts['shortfall'] ?? null,
            'order' => $this->order === null ? null : [
                'id' => (int) $this->order->id,
                'uuid' => $this->order->uuid,
                'receipt_number' => $this->order->receipt_number,
                'status' => $this->order->status?->value,
            ],
            'customer' => $account?->customer === null ? null : [
                'id' => (int) $account->customer->id,
                'uuid' => $account->customer->uuid,
                'name' => $account->customer->name,
                'phone' => $account->customer->phone,
            ],
            'rule' => $account?->rule === null ? null : [
                'id' => (int) $account->rule->id,
                'uuid' => $account->rule->uuid,
                'name' => $account->rule->name,
                'type' => $account->rule->type?->value,
            ],
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'resolution' => $review === null ? null : [
                'uuid' => $review->uuid,
                'note' => $review->resolution_note,
                'resolved_at' => $review->resolved_at?->toIso8601String(),
                'resolved_by' => $review->resolvedBy?->name,
            ],
        ];
    }

    /**
     * @return array{requested: array{points: int, stamps: int}, applied: array{points: int, stamps: int}, shortfall: array{points: int, stamps: int}}|null
     */
    private function shortfallAmounts(): ?array
    {
        $reason = (string) $this->reason;
        if (! preg_match(
            '/requested points=(\d+) stamps=(\d+); applied points=(\d+) stamps=(\d+); shortfall points=(\d+) stamps=(\d+);/',
            $reason,
            $matches,
        )) {
            return null;
        }

        return [
            'requested' => ['points' => (int) $matches[1], 'stamps' => (int) $matches[2]],
            'applied' => ['points' => (int) $matches[3], 'stamps' => (int) $matches[4]],
            'shortfall' => ['points' => (int) $matches[5], 'stamps' => (int) $matches[6]],
        ];
    }
}
