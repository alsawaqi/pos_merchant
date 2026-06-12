<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Production;

use App\Models\Production;
use App\Models\ProductionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P-G1 — one kitchen production batch for the read-only history page.
 * Quantities serialize as strings (decimal casts), dates as ISO-8601 —
 * the repo-wide resource conventions.
 *
 * @mixin Production
 */
class ProductionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'status' => $this->status,
            'quantity' => (string) $this->quantity,
            'product' => [
                'uuid' => $this->product?->uuid,
                'name' => $this->product?->name,
                'name_ar' => $this->product?->name_ar,
            ],
            'branch' => [
                'uuid' => $this->branch?->uuid,
                'name' => $this->branch?->name,
            ],
            'started_by' => $this->startedByStaff?->name,
            'finished_by' => $this->finishedByStaff?->name,
            'cancelled_by' => $this->cancelledByStaff?->name,
            'cancel_approved_by' => $this->cancelApprovedByStaff?->name,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            // P-G1.5 — the chef's per-batch expiry (NULL = never expires).
            'expires_at' => $this->expires_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'lines' => $this->lines->map(static fn (ProductionLine $line): array => [
                'ingredient_name' => $line->ingredient?->name,
                'ingredient_name_ar' => $line->ingredient?->name_ar,
                'quantity' => (string) $line->quantity,
                'unit' => $line->unit_at_time,
                'is_extra' => (bool) $line->is_extra,
            ])->values()->all(),
        ];
    }
}
