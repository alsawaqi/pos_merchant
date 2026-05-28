<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/restock-requests/{request:uuid}/approve
 * and POST /api/restock-requests/{request:uuid}/reject.
 *
 * Both share this same request because both transitions take an
 * optional/required note + nothing else. The rejection-note-
 * required check is enforced in the controller (which routes to
 * the right Action method based on the URL).
 *
 * Note is unbounded-optional here; the rejection-specific
 * requirement that it be non-empty lives in the Action — keeping
 * the validation in one place per business rule.
 */
class ReviewRestockRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
