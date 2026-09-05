<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Settings\SetBranchDineInRoundModeAction;
use App\Actions\Pos\Settings\SetBranchTableSessionsModeAction;
use App\Actions\Pos\Settings\SetDineInRoundModeAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Settings\UpdateBranchDineInRoundModeRequest;
use App\Http\Requests\Pos\Settings\UpdateDineInRoundModeRequest;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\CompanySetting;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QR-003 T0 — merchant-owned dine-in round policy, per branch with a company
 * default. Read: branches.view; write: branches.update. A restricted actor
 * may change only an allowed branch, never the company-wide default.
 */
class DineInRoundModeSettingController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetDineInRoundModeAction $setDefault,
        private readonly SetBranchDineInRoundModeAction $setBranch,
        private readonly SetBranchTableSessionsModeAction $setTableSessionsMode,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::BranchesView);

        // Opt-in companion snapshot preserves the exact existing T0 contract.
        if ($request->boolean('table_sessions')) {
            return response()->json(['data' => $this->tableSessionsModes($request->user())]);
        }

        return response()->json(['data' => $this->current($request->user())]);
    }

    public function update(UpdateDineInRoundModeRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::BranchesUpdate);

        if ($request->user()->allowedBranchIds() !== null) {
            abort(403);
        }

        $this->setDefault->handle($request->validated('mode'), $request->user());

        return response()->json(['data' => $this->current($request->user())]);
    }

    public function updateBranch(UpdateBranchDineInRoundModeRequest $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::BranchesUpdate);
        $this->refuseIfNotInTenant($branch);

        // Defence in depth: EnsureBranchScope also checks the bound branch.
        if (! $request->user()->canAccessBranchId((int) $branch->id)) {
            abort(403);
        }

        $mode = $request->validated('mode');
        $this->setBranch->handle($branch, $mode === 'inherit' ? null : $mode, $request->user());

        return response()->json(['data' => $this->current($request->user())]);
    }

    public function updateTableSessionsMode(Request $request, Branch $branch): JsonResponse
    {
        $this->ensure($request, MerchantPermission::BranchesUpdate);
        $this->refuseIfNotInTenant($branch);
        if (! $request->user()->canAccessBranchId((int) $branch->id)) {
            abort(403);
        }
        $validated = $request->validate(['mode' => ['required', 'string', 'in:off,shadow,live']]);
        $this->setTableSessionsMode->handle($branch, $validated['mode'], $request->user());

        return response()->json(['data' => $this->tableSessionsModes($request->user())]);
    }

    /** @return array{branches: list<array{uuid: string, table_sessions_mode: string}>} */
    private function tableSessionsModes(User $user): array
    {
        $companyId = $this->tenant->requiredId();
        $allowed = $user->allowedBranchIds();
        $branches = Branch::query()->where('company_id', $companyId)
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')->get();
        $settings = BranchSetting::query()->where('company_id', $companyId)
            ->where('key', 'table_sessions_mode')->whereIn('branch_id', $branches->modelKeys())
            ->get()->keyBy('branch_id');

        return ['branches' => $branches->map(static function (Branch $branch) use ($settings): array {
            $value = $settings->get($branch->id)?->value;

            return [
                'uuid' => $branch->uuid,
                'table_sessions_mode' => is_string($value) && in_array($value, ['off', 'shadow', 'live'], true) ? $value : 'off',
            ];
        })->values()->all()];
    }

    /**
     * @return array{company_default: string, company_default_editable: bool, branches: list<array{uuid: string, name: string, name_ar: ?string, code: string, mode: ?string, effective: string}>}
     */
    private function current(User $user): array
    {
        $companyId = $this->tenant->requiredId();
        $allowed = $user->allowedBranchIds();
        $companyValue = CompanySetting::query()
            ->where('company_id', $companyId)
            ->where('key', CompanySetting::KEY_DINE_IN_ROUND_MODE)
            ->value('value');
        $default = $this->mode($companyValue) ?? 'kitchen_direct';

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')
            ->get();
        $settings = BranchSetting::query()
            ->where('company_id', $companyId)
            ->where('key', BranchSetting::KEY_DINE_IN_ROUND_MODE)
            ->whereIn('branch_id', $branches->modelKeys())
            ->get()
            ->keyBy('branch_id');

        return [
            'company_default' => $default,
            'company_default_editable' => $user->can(MerchantPermission::BranchesUpdate->value) && $allowed === null,
            'branches' => $branches->map(function (Branch $branch) use ($settings, $default): array {
                $mode = $this->mode($settings->get($branch->id)?->value);

                return [
                    'uuid' => $branch->uuid,
                    'name' => $branch->name,
                    'name_ar' => $branch->name_ar,
                    'code' => $branch->code,
                    'mode' => $mode,
                    'effective' => $mode ?? $default,
                ];
            })->values()->all(),
        ];
    }

    /** The model JSON casts decode once; malformed or unknown values inherit. */
    private function mode(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['kitchen_direct', 'staff_confirm'], true)
            ? $value
            : null;
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Branch $branch): void
    {
        if ((int) $branch->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
