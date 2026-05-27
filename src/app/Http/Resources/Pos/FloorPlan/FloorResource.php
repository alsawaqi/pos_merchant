<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\FloorPlan;

use App\Models\Floor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a {@see Floor} for the merchant Floor Plan
 * page. Returns the floor's tables when the controller
 * eager-loaded them (the main /floor-plan page does), or
 * just the metadata + tables_count when listing without
 * children.
 *
 * @mixin Floor
 */
class FloorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'display_order' => $this->display_order,
            'status' => $this->status?->value,
            // tables_count comes from withCount('tables') when
            // listing; tables[] comes from with('tables') on
            // the show / index-with-children path.
            'tables_count' => $this->tables_count ?? $this->tables()->count(),
            'tables' => TableResource::collection($this->whenLoaded('tables')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
