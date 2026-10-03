<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Catalogue\UploadCatalogueImageRequest;
use App\Models\Company;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * LAUNCH-P4 B5 — photo upload for products, combos and categories.
 *
 *   POST /api/catalogue/images  (multipart: image, kind=product|category)
 *     → { url, path }
 *
 * The file lands on the public disk under products/{company uuid}/ (and
 * products/{company uuid}/categories/ for a category), named by a random
 * uuid, and is served at APP_URL/storage/... (production nginx serves
 * /storage/ straight from the storage volume). The form then saves the URL
 * in image_url, so QR web and the devices load it from our own host. Gated
 * by catalogue.manage; audited as catalogue.image.uploaded.
 */
class CatalogueImagesController extends Controller
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    public function store(UploadCatalogueImageRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::CatalogueManage->value)) {
            abort(403);
        }

        $companyId = $this->tenant->requiredId();
        $companyUuid = (string) Company::query()->whereKey($companyId)->value('uuid');
        $file = $request->file('image');
        $extension = self::EXTENSIONS[(string) $file->getMimeType()] ?? 'jpg';
        $kind = (string) ($request->validated()['kind'] ?? 'product');
        $folder = 'products/'.$companyUuid.($kind === 'category' ? '/categories' : '');

        $path = Storage::disk('public')->putFileAs($folder, $file, Str::uuid()->toString().'.'.$extension);
        if ($path === false) {
            return response()->json(['message' => 'The photo could not be stored. Try again.'], 500);
        }

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'catalogue.image.uploaded',
            actorUserId: $user->getKey(),
            companyId: $companyId,
            newValues: ['path' => $path, 'kind' => $kind, 'bytes' => (int) $file->getSize()],
        ));

        // image_url must be an absolute link (devices and QR web load it), so
        // a disk that answers a relative /storage/... path gets APP_URL.
        $url = Storage::disk('public')->url($path);
        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        return response()->json(['data' => [
            'url' => $url,
            'path' => $path,
        ]], 201);
    }
}
