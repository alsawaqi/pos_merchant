<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Offers\CreateOfferAction;
use App\Actions\Pos\Offers\DeleteOfferAction;
use App\Actions\Pos\Offers\PauseOfferAction;
use App\Actions\Pos\Offers\ResumeOfferAction;
use App\Actions\Pos\Offers\UpdateOfferAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Offers\CreateOfferRequest;
use App\Http\Requests\Pos\Offers\UpdateOfferRequest;
use App\Http\Resources\Pos\Offers\OfferResource;
use App\Models\Offer;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * P-F9 — offers / promotions CRUD + lifecycle (the DiscountsController
 * architecture, minus targets — selectors live INSIDE each offer's
 * type-specific config).
 *
 *   GET    /api/offers                          list
 *   GET    /api/offers/{offer:uuid}             show
 *   POST   /api/offers                          create
 *   PATCH  /api/offers/{offer:uuid}             partial update
 *   DELETE /api/offers/{offer:uuid}             soft delete
 *   POST   /api/offers/{offer:uuid}/pause       active -> paused
 *   POST   /api/offers/{offer:uuid}/resume      paused -> active
 *
 * Permission gating — offers share the discounts keys (same risk
 * class: both move money off the bill at POS time):
 *   discounts.view   for GETs
 *   discounts.manage for every write
 */
class OffersController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateOfferAction $create,
        private readonly UpdateOfferAction $update,
        private readonly DeleteOfferAction $delete,
        private readonly PauseOfferAction $pause,
        private readonly ResumeOfferAction $resume,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::DiscountsView);

        $offers = Offer::query()
            ->where('company_id', $this->tenant->requiredId())
            ->orderBy('name')
            ->get();

        return OfferResource::collection($offers);
    }

    public function show(Request $request, Offer $offer): OfferResource
    {
        $this->ensure($request, MerchantPermission::DiscountsView);
        $this->refuseIfNotInTenant($offer);

        return OfferResource::make($offer);
    }

    public function store(CreateOfferRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);

        try {
            $offer = $this->create->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new OfferResource($offer))->resolve($request),
        ], 201);
    }

    public function update(UpdateOfferRequest $request, Offer $offer): OfferResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($offer);

        try {
            $updated = $this->update->handle($offer, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return OfferResource::make($updated);
    }

    public function destroy(Request $request, Offer $offer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($offer);

        $this->delete->handle($offer, $request->user());

        return response()->json(['data' => null], 204);
    }

    public function pause(Request $request, Offer $offer): OfferResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($offer);

        try {
            $updated = $this->pause->handle($offer, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return OfferResource::make($updated);
    }

    public function resume(Request $request, Offer $offer): OfferResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::DiscountsManage);
        $this->refuseIfNotInTenant($offer);

        try {
            $updated = $this->resume->handle($offer, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return OfferResource::make($updated);
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Offer $offer): void
    {
        if ((int) $offer->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
