<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Messaging;

use App\Models\PortalMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/messages — send a portal-inbox message. Target
 * resolution (tenancy, role existence, F5 scope) happens in
 * {@see \App\Actions\Pos\Messaging\SendPortalMessageAction}.
 */
class SendPortalMessageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', Rule::in([
                PortalMessage::TARGET_USER,
                PortalMessage::TARGET_ROLE,
                PortalMessage::TARGET_BRANCH,
            ])],
            'target_user_id' => ['required_if:target_type,user', 'nullable', 'integer', 'min:1'],
            'target_role' => ['required_if:target_type,role', 'nullable', 'string', 'max:64'],
            'target_branch_uuid' => ['required_if:target_type,branch', 'nullable', 'string', 'uuid'],
            'subject' => ['nullable', 'string', 'max:191'],
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }
}
