<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Discounts\CreateDiscountAction;
use App\Actions\Pos\Discounts\DeleteDiscountAction;
use App\Actions\Pos\Discounts\PauseDiscountAction;
use App\Actions\Pos\Discounts\ResumeDiscountAction;
use App\Actions\Pos\Discounts\SetDiscountTargetsAction;
use App\Actions\Pos\Discounts\UpdateDiscountAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Discounts\CreateDiscountRequest;
use App\Http\Requests\Pos\Discounts\SetDiscountTargetsRequest;
use App\Http\Requests\Pos\Discounts\UpdateDiscountRequest;
use App\Http\Resources\Pos\Discounts\DiscountResource;
use App\Models\Discount;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase 6d — discount rule CRUD + lifecycle + targets sync.
 *
 *   GET    /api/discounts                                list with targets_count
 *   GET    /api/discounts/{discount:uuid}                show with targets loaded
 *   POST   /api/discounts                                create
 *   PATCH  /api/discounts/{discount:uuid}                partial update
 *   DELETE /api/discounts/{discount:uuid}                soft delete
 *   POST   /api/discounts/{discount:uuid}/pause          active -> paused
 *   POST   /api/discounts/{discount:uuid}/resume         paused -> active
 *   PUT    /api/discounts/{discount:uuid}/targets        sync targets list
 *
 * Permission gating:
 *   discounts.view   for GETs
 *   discounts.manage for every write
 */
class DiscountsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateDiscountAction $create,
        private readonly UpdateDiscountAction $update,
        private readonly DeleteDiscountAction $delete,
        private readonly PauseDiscountAction $pause,
        private readonly ResumeDiscountAction $resume,
        private readonly SetDiscountTargetsAction $setTargets,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::DiscountsView);

        $discounts = Discount::query()
            ->where('company_id', $this->tenant->requiredId())
            ->withCount('targets')
            ->with('targets')
            ->orderBy('name')
            ->get();

        return DiscountResource::collection($discounts);
    }

    public function show(Request $request, Discount $discount): DiscountResource
    {
        $this->ensure($request, MerchantPermission::DiscountsView);
        $this->refuseIfNotInTenant($discount);

        $discount->load('targets')->loadCount('targets');

        return DiscountResource::make($discount);
    }

    public function store(CreateDiscountRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);

        try {
            $discount = $this->create->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $discount->load('targets')->loadCount('targets');

        return response()->json([
            'data' => (new DiscountResource($discount))->resolve($request),
        ], 201);
    }

    public function update(UpdateDiscountRequest $request, Discount $discount): DiscountResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($discount);

        try {
            $updated = $this->update->handle($discount, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load('targets')->loadCount('targets');

        return DiscountResource::make($updated);
    }

    public function destroy(Request $request, Discount $discount): JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($discount);

        $this->delete->handle($discount, $request->user());

        return response()->json(['data' => null], 204);
    }

    public function pause(Request $request, Discount $discount): DiscountResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($discount);

        try {
            $updated = $this->pause->handle($discount, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load('targets')->loadCount('targets');

        return DiscountResource::make($updated);
    }

    public function resume(Request $request, Discount $discount): DiscountResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($discount);

        try {
            $updated = $this->resume->handle($discount, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load('targets')->loadCount('targets');

        return DiscountResource::make($updated);
    }

    public function syncTargets(SetDiscountTargetsRequest $request, Discount $discount): DiscountResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($discount);

        try {
            $updated = $this->setTargets->handle(
                $discount,
                $request->validated()['targets'] ?? [],
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $updated->load('targets')->loadCount('targets');

        return DiscountResource::make($updated);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Discount $discount): void
    {
        if ((int) $discount->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
