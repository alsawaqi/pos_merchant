<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Settings\SetOrderNumberingAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Settings\UpdateOrderNumberingRequest;
use App\Models\CompanySetting;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P-F8 — merchant-defined order numbering.
 *
 *   GET /api/settings/order-numbering → the current policy (normalised)
 *   PUT /api/settings/order-numbering → set it
 *
 * The merchant chooses how POS order numbers look (prefix + zero-padded
 * counter, e.g. KLD-0042), whether each BRANCH has its own sequence or the
 * COMPANY shares one, and whether the counter restarts daily. pos_api owns
 * the actual allocation (POST /device/orders/next-number). Both verbs are
 * gated on orders.cancel — the same merchant-policy gate as the sibling
 * settings on this page cluster. Tenant-scoped via MerchantTenantContext.
 * Default (no row yet) is DISABLED with the standard shape, matching what
 * pos_api emits to the device.
 */
class OrderNumberingSettingController extends Controller
{
    /**
     * @var array{enabled: bool, prefix: string, pad: int, scope: string, daily_reset: bool}
     */
    private const DEFAULTS = [
        'enabled' => false,
        'prefix' => '',
        'pad' => 4,
        'scope' => 'branch',
        'daily_reset' => false,
    ];

    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SetOrderNumberingAction $setNumbering,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensure($request);

        return response()->json(['data' => $this->current()]);
    }

    public function update(UpdateOrderNumberingRequest $request): JsonResponse
    {
        $this->ensure($request);

        $validated = $request->validated();
        $saved = $this->setNumbering->handle([
            'enabled' => (bool) $validated['enabled'],
            // ConvertEmptyStringsToNull turned '' into null — restore ''.
            'prefix' => (string) ($validated['prefix'] ?? ''),
            'pad' => (int) $validated['pad'],
            'scope' => (string) $validated['scope'],
            'daily_reset' => (bool) $validated['daily_reset'],
        ], $request->user());

        return response()->json(['data' => $saved]);
    }

    /**
     * @return array{enabled: bool, prefix: string, pad: int, scope: string, daily_reset: bool}
     */
    private function current(): array
    {
        $value = CompanySetting::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('key', CompanySetting::KEY_ORDER_NUMBERING)
            ->value('value');

        $stored = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($stored)) {
            return self::DEFAULTS;
        }

        // Normalise read-back the same way pos_api does, so the form
        // always renders the full five-key shape.
        return [
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'prefix' => mb_substr(trim((string) ($stored['prefix'] ?? '')), 0, 8),
            'pad' => max(3, min(6, (int) ($stored['pad'] ?? self::DEFAULTS['pad']))),
            'scope' => ($stored['scope'] ?? 'branch') === 'company' ? 'company' : 'branch',
            'daily_reset' => (bool) ($stored['daily_reset'] ?? false),
        ];
    }

    private function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::OrdersCancel->value)) {
            abort(403);
        }
    }
}
