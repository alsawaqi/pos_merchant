<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Settings\SetBranchQrTableCardSettingsAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Settings\UpdateBranchQrTableCardsRequest;
use App\Models\Branch;
use App\Models\BranchSetting;
use App\Models\User;
use App\Support\MerchantTenantContext;
use App\Support\TableCardQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QrTableCardsSettingController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetBranchQrTableCardSettingsAction $setSettings,
        private readonly TableCardQr $qr,
    ) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(MerchantPermission::BranchesView->value), 403);

        return response()->json(['data' => $this->current($request->user())]);
    }

    public function update(UpdateBranchQrTableCardsRequest $request, Branch $branch): JsonResponse
    {
        abort_unless($request->user()?->can(MerchantPermission::BranchesUpdate->value), 403);
        abort_if((int) $branch->company_id !== $this->tenant->requiredId(), 404);
        abort_unless($request->user()->canAccessBranchId((int) $branch->id), 403);
        $this->setSettings->handle(
            $branch, $request->validated('card_enabled'), $request->validated('geofence_mode'), $request->user(),
        );

        return response()->json(['data' => $this->current($request->user())]);
    }

    /** @return array<string, mixed> */
    private function current(User $user): array
    {
        $companyId = $this->tenant->requiredId();
        $allowed = $user->allowedBranchIds();
        $branches = Branch::query()->where('company_id', $companyId)
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')->get();
        $settings = BranchSetting::query()->where('company_id', $companyId)
            ->whereIn('key', ['qr_table_card_enabled', 'qr_scan_geofence_mode'])
            ->whereIn('branch_id', $branches->modelKeys())->get()->groupBy('branch_id');

        return [
            'web_base_url_configured' => $this->qr->baseUrl() !== '',
            'branches' => $branches->map(static function (Branch $branch) use ($settings): array {
                $values = $settings->get($branch->id)?->keyBy('key');
                $enabled = $values?->get('qr_table_card_enabled')?->value;
                $geofence = $values?->get('qr_scan_geofence_mode')?->value;

                return [
                    'uuid' => $branch->uuid, 'name' => $branch->name, 'name_ar' => $branch->name_ar,
                    'card_enabled' => is_string($enabled) && in_array($enabled, ['off', 'on'], true) ? $enabled : 'off',
                    'geofence_mode' => is_string($geofence) && in_array($geofence, ['off', 'advisory', 'enforce'], true) ? $geofence : 'advisory',
                    'fenced' => $branch->latitude !== null && $branch->longitude !== null,
                ];
            })->values()->all(),
        ];
    }
}
