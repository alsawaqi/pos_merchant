<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\MerchantPermission;
use App\Models\Product;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PUT /api/products/{product:uuid}/branches (LAUNCH-P4 H6 + H7):
 *
 *   { branch_scope: 'all' | 'selected', branch_ids: [ids] }
 *
 * 'all' = every branch sells it; 'selected' = only the listed branches (at
 * least one). No shelf counts are accepted any more — they change only
 * through the stock actions. Each branch id's ownership is verified in the
 * action.
 */
class SyncProductBranchesRequest extends FormRequest
{
    /**
     * The gates run BEFORE validation so a refused caller gets its 403/404
     * whatever the payload: catalogue.manage, the tenant (404 first, so a
     * foreign uuid never reveals itself) and the HQ-only branch rule (P-G5).
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User || ! $user->can(MerchantPermission::CatalogueManage->value)) {
            return false;
        }
        $product = $this->route('product');
        if ($product instanceof Product && (int) $product->company_id !== app(MerchantTenantContext::class)->requiredId()) {
            abort(404);
        }
        BranchScope::ensureUnrestricted($user, 'Branch availability is managed by accounts with access to all branches.');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::branchRules('');
    }

    /**
     * The same rules re-rooted (the wizard nests them under `branches.`).
     *
     * @return array<string, mixed>
     */
    public static function branchRules(string $prefix): array
    {
        return [
            $prefix.'branch_scope' => ['required', 'string', Rule::in([Product::SCOPE_ALL, Product::SCOPE_SELECTED])],
            $prefix.'branch_ids' => ['present', 'array', 'max:500', 'required_if:'.$prefix.'branch_scope,'.Product::SCOPE_SELECTED],
            $prefix.'branch_ids.*' => ['integer', 'min:1'],
        ];
    }
}
