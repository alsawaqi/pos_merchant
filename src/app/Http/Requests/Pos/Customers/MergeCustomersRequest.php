<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 6a — customer merge.
 *
 * The survivor is the route customer ({customer:uuid}); the body names the
 * DUPLICATE to fold in + retire. Existence + tenant ownership of the source
 * are resolved in the controller. customers.manage is enforced there too.
 */
class MergeCustomersRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'source_uuid' => ['required', 'uuid'],
        ];
    }
}
