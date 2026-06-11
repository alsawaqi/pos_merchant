<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P-F9 — offer / promotion types. Each type pairs with its own strict
 * `config` JSON shape (validated by {@see \App\Support\OfferConfig});
 * the POS device evaluates them with a pure engine.
 */
enum OfferType: string
{
    /** Buy X get Y free / % off (product/category selectors). */
    case Bogo = 'bogo';

    /**
     * Fixed-price meal deal of N pick-groups. ALWAYS cashier-picked —
     * the write actions force auto_apply=false for this type.
     */
    case Bundle = 'bundle';

    /** N of a selector for a fixed price (e.g. 3 for 1.000 OMR). */
    case MultiBuy = 'multi_buy';

    /** Buy N from a selector, the cheapest M of them free. */
    case CheapestFree = 'cheapest_free';

    /** Order subtotal ≥ X → % off / fixed off / free product. */
    case SpendGet = 'spend_get';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
