<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Loyalty;

use App\Models\LoyaltyRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoyaltyRule
 */
class LoyaltyRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type?->value,
            'config' => $this->config_json ?? [],
            'validity_start' => $this->validity_start?->toIso8601String(),
            'validity_end' => $this->validity_end?->toIso8601String(),
            'status' => $this->status?->value,
            // Composes status + validity window for the list chip.
            'currently_active' => $this->isActiveAt(now()),
            'accounts_count' => $this->whenCounted('accounts'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
