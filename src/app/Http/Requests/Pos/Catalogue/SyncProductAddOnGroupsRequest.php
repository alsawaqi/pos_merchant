<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates PUT /api/products/{product:uuid}/addon-groups.
 *
 * Caller sends the desired complete list of group uuids; the
 * Action performs an idempotent sync. Empty array = detach all.
 *
 * Per-uuid tenant ownership is verified in the Action (it
 * resolves the uuids in one query and aborts if any don't
 * match). Validating it here too would duplicate effort.
 */
class SyncProductAddOnGroupsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'group_uuids' => ['present', 'array', 'max:50'],
            'group_uuids.*' => ['string', 'uuid'],
        ];
    }
}
