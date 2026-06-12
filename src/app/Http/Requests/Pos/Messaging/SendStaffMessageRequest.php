<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Messaging;

use App\Models\StaffMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/staff-messages — compose a device announcement.
 * Target resolution (uuid → row, tenancy, F5 scope) happens in
 * {@see \App\Actions\Pos\Messaging\SendStaffMessageAction}.
 */
class SendStaffMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', Rule::in([
                StaffMessage::TARGET_STAFF,
                StaffMessage::TARGET_BRANCH,
                StaffMessage::TARGET_COMPANY,
            ])],
            'target_branch_uuid' => ['required_if:target_type,branch', 'nullable', 'string', 'uuid'],
            'target_staff_uuid' => ['required_if:target_type,staff', 'nullable', 'string', 'uuid'],
            'title' => ['nullable', 'string', 'max:191'],
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
