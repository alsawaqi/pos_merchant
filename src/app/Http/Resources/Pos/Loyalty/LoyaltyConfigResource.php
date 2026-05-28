<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Loyalty;

use App\Models\CustomerLoyaltyConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerLoyaltyConfig
 */
class LoyaltyConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'points_per_omr' => (int) $this->points_per_omr,
            'baisas_per_point' => (int) $this->baisas_per_point,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
