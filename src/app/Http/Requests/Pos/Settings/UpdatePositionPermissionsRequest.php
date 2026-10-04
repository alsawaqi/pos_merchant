<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use App\Support\MerchantTenantContext;
use App\Support\PositionPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * LAUNCH-P5 B1 — validate a staff permissions save.
 *
 *   { "permissions": { "<position>": { "actions": { "<key>": true|false },
 *                                      "discount_max_percent": 0..100 } } }
 *
 * Positions and action keys must be the fixed ones (action keys contain a
 * dot, so they are checked here by hand rather than with nested rule keys).
 * Ticks must be real booleans and the limit a whole number 0..100. The
 * kitchen position always opens the kitchen screen, and after the change at
 * least one position must still be able to approve (otherwise nothing that
 * needs an approval could ever be done). Permission gating lives in the
 * controller (staff.permissions.manage).
 */
class UpdatePositionPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $permissions = $this->input('permissions');
            if (! is_array($permissions)) {
                return;
            }

            foreach ($permissions as $position => $row) {
                if (! in_array($position, PositionPermissions::POSITIONS, true)) {
                    $v->errors()->add('permissions', "Unknown position: {$position}.");

                    continue;
                }
                if (! is_array($row)) {
                    $v->errors()->add("permissions.{$position}", 'Each position must be an object.');

                    continue;
                }
                foreach (array_keys($row) as $field) {
                    if (! in_array($field, ['actions', 'discount_max_percent'], true)) {
                        $v->errors()->add("permissions.{$position}", "Unknown field: {$field}.");
                    }
                }
                if (array_key_exists('actions', $row)) {
                    if (! is_array($row['actions'])) {
                        $v->errors()->add("permissions.{$position}", 'actions must be an object.');
                    } else {
                        foreach ($row['actions'] as $action => $allowed) {
                            if (! in_array($action, PositionPermissions::ACTIONS, true)) {
                                $v->errors()->add("permissions.{$position}", "Unknown action: {$action}.");
                            } elseif (! is_bool($allowed)) {
                                $v->errors()->add("permissions.{$position}", "{$action} must be true or false.");
                            } elseif ($allowed === false && in_array($action, PositionPermissions::ALWAYS_ON[$position] ?? [], true)) {
                                $v->errors()->add("permissions.{$position}", 'The kitchen position always opens the kitchen screen.');
                            }
                        }
                    }
                }
                if (array_key_exists('discount_max_percent', $row)) {
                    $limit = $row['discount_max_percent'];
                    if (! is_int($limit) || $limit < 0 || $limit > 100) {
                        $v->errors()->add("permissions.{$position}", 'The maximum discount must be a whole number from 0 to 100.');
                    }
                }
            }

            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }
            $next = PositionPermissions::overlay(PositionPermissions::forCompany($companyId), $permissions);
            $approvers = array_filter(
                PositionPermissions::POSITIONS,
                static fn (string $p): bool => $next[$p]['actions']['approvals.give'],
            );
            if ($approvers === []) {
                $v->errors()->add('permissions', 'At least one position must be able to approve.');
            }
        });
    }

    /**
     * @return array<string, array{actions?: array<string, bool>, discount_max_percent?: int}>
     */
    public function changes(): array
    {
        /** @var array<string, array{actions?: array<string, bool>, discount_max_percent?: int}> $permissions */
        $permissions = $this->input('permissions', []);

        return $permissions;
    }
}
