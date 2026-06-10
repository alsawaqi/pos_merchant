<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConfirmTwoFactorAction;
use App\Actions\Auth\DisableTwoFactorAction;
use App\Actions\Auth\GenerateTwoFactorSecretAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Self-service TOTP 2FA enrolment for the signed-in merchant portal
 * user (Phase D8). Per-user opt-in:
 *
 *   POST   /auth/two-factor          start: mint + store the secret,
 *                                    return QR SVG + manual secret
 *   POST   /auth/two-factor/confirm  prove possession with a live
 *                                    code → ENABLED + one-time
 *                                    recovery codes
 *   DELETE /auth/two-factor          step-up disable (password +
 *                                    code or recovery code)
 *
 * All three sit behind the authed session middleware group; every
 * transition is audited inside the Actions.
 */
class TwoFactorController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(Request $request, GenerateTwoFactorSecretAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($action->handle($user));
    }

    /**
     * @throws ValidationException
     */
    public function confirm(ConfirmTwoFactorRequest $request, ConfirmTwoFactorAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $recoveryCodes = $action->handle($user, (string) $request->validated()['code']);

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
            'two_factor_enabled' => true,
            // Plaintext recovery codes — surfaced exactly ONCE.
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function destroy(DisableTwoFactorRequest $request, DisableTwoFactorAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        $action->handle(
            user: $user,
            currentPassword: (string) $validated['current_password'],
            code: $validated['code'] ?? null,
            recoveryCode: $validated['recovery_code'] ?? null,
        );

        return response()->json([
            'message' => 'Two-factor authentication disabled.',
            'two_factor_enabled' => false,
        ]);
    }
}
