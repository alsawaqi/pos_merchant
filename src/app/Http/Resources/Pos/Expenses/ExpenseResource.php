<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Expenses;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            // null branch_id = a general / company-wide expense.
            'branch_id' => $this->branch_id !== null ? (int) $this->branch_id : null,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            // v2 #7: the per-company category key (string). The UI maps it to a
            // display name via the expense-categories list.
            'category' => $this->category,
            'amount' => (string) $this->amount,
            // PT — the tax portion of `amount` (the gross paid), + the % used.
            'tax_amount' => (string) $this->tax_amount,
            'tax_rate' => $this->tax_rate !== null ? (string) $this->tax_rate : null,
            'note' => $this->note,
            'receipt_photo_path' => $this->receipt_photo_path,
            'logged_by_pos_staff_id' => $this->logged_by_pos_staff_id !== null ? (int) $this->logged_by_pos_staff_id : null,
            'logged_by_portal_user_id' => $this->logged_by_portal_user_id !== null ? (int) $this->logged_by_portal_user_id : null,
            // Display label resolved from whichever logger is set.
            // Relies on the controller eager-loading loggedByStaff +
            // loggedByUser to avoid an N+1 on the list.
            'logged_by_name' => $this->loggedByStaff?->name ?? $this->loggedByUser?->name,
            'logged_at' => $this->logged_at?->toIso8601String(),
            'status' => $this->status?->value,
            'reviewed_by_portal_user_id' => $this->reviewed_by_portal_user_id !== null ? (int) $this->reviewed_by_portal_user_id : null,
            'reviewed_by_name' => $this->reviewedBy?->name,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
