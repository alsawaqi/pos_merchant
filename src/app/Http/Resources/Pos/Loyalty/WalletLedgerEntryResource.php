<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Loyalty;

use App\Models\CustomerWalletLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerWalletLedgerEntry
 */
class WalletLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'entry_type' => $this->entry_type?->value,
            // SIGNED decimal:3 OMR — kept as a string so the
            // frontend can compare exact baisas precision
            // without parseFloat.
            'amount_delta' => (string) $this->amount_delta,
            'balance_after' => (string) $this->balance_after,
            'reason' => $this->reason,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?array => $this->recordedBy === null ? null : [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ]),
        ];
    }
}
