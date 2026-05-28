<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Discounts;

use App\Models\DiscountTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DiscountTarget
 */
class DiscountTargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'target_type' => $this->target_type?->value,
            'target_id' => (int) $this->target_id,
        ];
    }
}
