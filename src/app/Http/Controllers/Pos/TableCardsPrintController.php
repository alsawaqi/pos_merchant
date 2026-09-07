<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Floor;
use App\Support\MerchantTenantContext;
use App\Support\TableCardQr;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TableCardsPrintController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly TableCardQr $qr,
    ) {}

    public function __invoke(Request $request, Branch $branch): Response
    {
        abort_unless($request->user()?->can(MerchantPermission::FloorPlanView->value), 403);
        $companyId = $this->tenant->requiredId();
        abort_if((int) $branch->company_id !== $companyId, 404);
        abort_unless($request->user()->canAccessBranchId((int) $branch->id), 403);
        $floorUuid = $request->validate(['floor' => ['sometimes', 'required', 'uuid']])['floor'] ?? null;
        if ($floorUuid !== null) {
            Floor::query()->where('company_id', $companyId)->where('branch_id', $branch->id)
                ->where('uuid', $floorUuid)->firstOrFail();
        }
        $configured = $this->qr->baseUrl() !== '';
        $cards = [];
        if ($configured) {
            $floors = Floor::query()->where('company_id', $companyId)->where('branch_id', $branch->id)
                ->active()->when($floorUuid !== null, fn ($query) => $query->where('uuid', $floorUuid))
                ->with(['tables' => fn ($query) => $query->where('company_id', $companyId)->active()])
                ->orderBy('display_order')->orderBy('id')->get();
            foreach ($floors as $floor) {
                foreach ($floor->tables as $table) {
                    $url = $this->qr->url($table->qr_token);
                    $cards[] = [
                        'uuid' => $table->uuid, 'label' => $table->label,
                        'floor' => $floor->name, 'floor_ar' => $floor->name_ar,
                        'url' => $url, 'svg' => $this->qr->svg($url),
                    ];
                }
            }
        }
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $copy = json_decode(file_get_contents(resource_path("js/locales/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR)['print']['table_cards'];

        return response()->view('print.table-cards', [
            'branch' => $branch, 'company' => $branch->company, 'cards' => $cards,
            'configured' => $configured, 'copy' => $copy, 'locale' => $locale,
        ])->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'private, no-store');
    }
}
