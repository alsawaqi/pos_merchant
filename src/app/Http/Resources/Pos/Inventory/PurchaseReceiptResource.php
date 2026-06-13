<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Inventory;

use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptCharge;
use App\Models\PurchaseReceiptLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PD6 — a Saved Purchase Receipt. The list (index) gets the header + supplier +
 * lines_count; the detail (show) additionally carries the full lines + charges
 * (loaded via with()). Money stays a 3-decimal string.
 *
 * @mixin PurchaseReceipt
 */
class PurchaseReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'reference' => $this->reference,
            'status' => $this->status,
            'note' => $this->note,
            'items_total' => (string) $this->items_total,
            'charges_total' => (string) $this->charges_total,
            'grand_total' => (string) $this->grand_total,
            'received_at' => $this->received_at?->toIso8601String(),
            'supplier' => $this->whenLoaded('supplier', fn (): ?array => $this->supplier !== null ? [
                'uuid' => $this->supplier->uuid,
                'name' => $this->supplier->name,
            ] : null),
            'recorded_by' => $this->whenLoaded('recordedByUser', fn (): ?string => $this->recordedByUser?->name),
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines
                ->map(fn (PurchaseReceiptLine $line): array => [
                    'item_type' => $line->item_type,
                    'item_name' => $line->item_name,
                    'quantity' => (string) $line->quantity,
                    'unit' => $line->unit,
                    'line_cost' => (string) $line->line_cost,
                    'expense_category' => $line->expense_category,
                    'allocations' => $line->allocations_json ?? [],
                ])->all()),
            'charges' => $this->whenLoaded('charges', fn (): array => $this->charges
                ->map(fn (PurchaseReceiptCharge $charge): array => [
                    'name' => $charge->name,
                    'expense_category' => $charge->expense_category,
                    'amount' => (string) $charge->amount,
                ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
