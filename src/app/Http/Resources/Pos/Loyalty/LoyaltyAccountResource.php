<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Loyalty;

use App\Models\LoyaltyAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoyaltyAccount
 */
class LoyaltyAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'stamp_count' => (int) $this->stamp_count,
            'point_balance' => (int) $this->point_balance,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'rule' => $this->whenLoaded('rule', fn (): array => [
                'id' => $this->rule->id,
                'uuid' => $this->rule->uuid,
                'name' => $this->rule->name,
                'type' => $this->rule->type?->value,
            ]),
            'recent_transactions' => LoyaltyTransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
