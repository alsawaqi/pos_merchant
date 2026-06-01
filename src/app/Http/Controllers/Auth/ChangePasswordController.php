<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password change for the signed-in merchant portal user,
 * which also clears the must_change_password flag the platform admin
 * sets when minting the account (the forced-first-login path). The
 * current password is required to authorise the change.
 */
class ChangePasswordController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('auth.password')],
            ]);
        }

        $user->forceFill([
            'password' => $validated['new_password'], // hashed by the model cast
            'must_change_password' => false,
        ])->save();

        return response()->json([
            'message' => 'Password changed.',
            'must_change_password' => false,
        ]);
    }
}
