<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Catalogue;

use App\Models\AddOn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AddOn
 */
class AddOnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'add_on_group_id' => $this->add_on_group_id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            // Money as string — decimal:3 cast preserves
            // precision (NEVER parseFloat for money).
            'price_delta' => (string) $this->price_delta,
            // Phase B — pre-selected in the POS customize sheet.
            'is_default' => (bool) $this->is_default,
            'display_order' => $this->display_order,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
