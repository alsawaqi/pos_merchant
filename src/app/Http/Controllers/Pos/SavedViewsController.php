<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\SavedViews\StoreSavedViewRequest;
use App\Http\Requests\Pos\SavedViews\UpdateSavedViewRequest;
use App\Http\Resources\Pos\SavedViews\SavedViewResource;
use App\Models\SavedView;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saved views — per-user filter presets for any portal screen.
 *
 *   GET    /api/saved-views?view_key=...   → the caller's presets (optionally
 *                                            for one screen)
 *   POST   /api/saved-views                → create one
 *   PATCH  /api/saved-views/{savedView:uuid} → rename / re-filter / set default
 *   DELETE /api/saved-views/{savedView:uuid} → delete
 *
 * No MerchantPermission gate — these are PERSONAL bookmarks; every
 * authenticated merchant user manages their own. Authorisation is pure
 * ownership: every query is scoped to (company_id, user_id) and a row the
 * caller doesn't own 404s. Tenant pinned via MerchantTenantContext.
 */
class SavedViewsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SavedView::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('user_id', $request->user()->getKey());

        if ($request->filled('view_key')) {
            $query->where('view_key', (string) $request->query('view_key'));
        }

        $views = $query
            ->orderBy('view_key')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return SavedViewResource::collection($views);
    }

    public function store(StoreSavedViewRequest $request): JsonResponse
    {
        $companyId = $this->tenant->requiredId();
        $userId = $request->user()->getKey();
        $data = $request->validated();

        $this->assertNameIsFree($companyId, $userId, $data['view_key'], $data['name']);

        $view = DB::transaction(function () use ($companyId, $userId, $data): SavedView {
            $isDefault = (bool) ($data['is_default'] ?? false);
            if ($isDefault) {
                $this->clearDefault($companyId, $userId, $data['view_key']);
            }

            return SavedView::query()->create([
                'company_id' => $companyId,
                'user_id' => $userId,
                'view_key' => $data['view_key'],
                'name' => $data['name'],
                'filters' => $data['filters'] ?? [],
                'is_default' => $isDefault,
            ]);
        });

        return response()->json([
            'data' => (new SavedViewResource($view))->resolve($request),
        ], 201);
    }

    public function update(UpdateSavedViewRequest $request, SavedView $savedView): SavedViewResource
    {
        $this->refuseIfNotOwn($request, $savedView);
        $data = $request->validated();

        if (array_key_exists('name', $data) && $data['name'] !== $savedView->name) {
            $this->assertNameIsFree(
                (int) $savedView->company_id,
                (int) $savedView->user_id,
                $savedView->view_key,
                $data['name'],
                $savedView->id,
            );
        }

        DB::transaction(function () use ($data, $savedView): void {
            if (array_key_exists('is_default', $data) && $data['is_default'] && ! $savedView->is_default) {
                $this->clearDefault((int) $savedView->company_id, (int) $savedView->user_id, $savedView->view_key);
            }

            $savedView->fill([
                'name' => $data['name'] ?? $savedView->name,
                'filters' => array_key_exists('filters', $data) ? ($data['filters'] ?? []) : $savedView->filters,
                'is_default' => $data['is_default'] ?? $savedView->is_default,
            ])->save();
        });

        return SavedViewResource::make($savedView->fresh());
    }

    public function destroy(Request $request, SavedView $savedView): JsonResponse
    {
        $this->refuseIfNotOwn($request, $savedView);

        $savedView->delete();

        return response()->json(['data' => null], 204);
    }

    /**
     * At most one default per (user, view_key): clear any existing default on
     * that screen before setting a new one.
     */
    private function clearDefault(int $companyId, int $userId, string $viewKey): void
    {
        SavedView::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('view_key', $viewKey)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function assertNameIsFree(int $companyId, int $userId, string $viewKey, string $name, ?int $exceptId = null): void
    {
        $exists = SavedView::query()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('view_key', $viewKey)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'You already have a saved view with this name on this screen.',
            ]);
        }
    }

    private function refuseIfNotOwn(Request $request, SavedView $savedView): void
    {
        if ((int) $savedView->company_id !== $this->tenant->requiredId()
            || (int) $savedView->user_id !== (int) $request->user()->getKey()) {
            abort(404);
        }
    }
}
