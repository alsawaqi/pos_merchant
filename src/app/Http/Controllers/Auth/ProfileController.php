<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\UpdateOwnProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Self-service profile update for the signed-in merchant portal
 * user (Phase D7). Only the display name is editable — email is
 * the admin-managed login identifier (see UpdateProfileRequest).
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, UpdateOwnProfileAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user = $action->handle($user, $request->validated());

        return response()->json([
            'message' => 'Profile updated.',
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
