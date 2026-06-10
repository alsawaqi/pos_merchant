<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\OrderReasons;

use App\Models\VoidReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VoidReason
 */
class VoidReasonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'affects_inventory' => (bool) $this->affects_inventory,
            'requires_manager' => (bool) $this->requires_manager,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
