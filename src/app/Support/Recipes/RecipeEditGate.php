<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Enums\MerchantPermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * LAUNCH-P3 P3-3 — the "Edit recipes" permission (catalogue.recipes.manage).
 *
 * Required to change a product recipe, a prep item (its recipe and yield) or
 * an add-on option's stock-usage lines; catalogue.manage alone no longer
 * allows those edits. Throws AuthorizationException (rendered as 403) — NOT
 * a RuntimeException, so the controllers' "RuntimeException → 422" catches
 * never turn a refusal into a validation error.
 */
final class RecipeEditGate
{
    public const MESSAGE = 'Changing recipes needs the "Edit recipes" permission.';

    public static function allows(?User $user): bool
    {
        return $user !== null && $user->can(MerchantPermission::CatalogueRecipesManage->value);
    }

    /** @throws AuthorizationException */
    public static function ensure(?User $user): void
    {
        if (! self::allows($user)) {
            throw new AuthorizationException(self::MESSAGE);
        }
    }
}
