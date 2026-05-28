<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Loyalty;

use App\Models\LoyaltyTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoyaltyTransaction
 */
class LoyaltyTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'loyalty_account_id' => (int) $this->loyalty_account_id,
            'type' => $this->type?->value,
            'points_delta' => (int) $this->points_delta,
            'stamps_delta' => (int) $this->stamps_delta,
            'balance_after_points' => (int) $this->balance_after_points,
            'balance_after_stamps' => (int) $this->balance_after_stamps,
            'reason' => $this->reason,
            'order_id' => $this->order_id !== null ? (int) $this->order_id : null,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
