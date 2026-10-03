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
 *
 * Fix order 1, L8 — ONE rule for every recipe write (product recipe PUT,
 * wizard recipe, prep items, add-on stock usage, and the changes that switch
 * recipe deduction: a stock-mode change on a product with a recipe, an
 * add-on's linked product): "Edit recipes" + catalogue.view. A chef role
 * [catalogue.view, Edit recipes] edits recipes without managing the rest of
 * the catalogue; "Edit recipes" without catalogue.view grants nothing.
 */
final class RecipeEditGate
{
    public const MESSAGE = 'Changing recipes needs the "Edit recipes" permission (together with "See categories + products + add-ons").';

    public static function allows(?User $user): bool
    {
        return $user !== null
            && $user->can(MerchantPermission::CatalogueRecipesManage->value)
            && $user->can(MerchantPermission::CatalogueView->value);
    }

    /** @throws AuthorizationException */
    public static function ensure(?User $user): void
    {
        if (! self::allows($user)) {
            throw new AuthorizationException(self::MESSAGE);
        }
    }
}
