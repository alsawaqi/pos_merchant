<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * LAUNCH packaging add-on (server review M1) — a cooked product is made in
 * batches before any order exists, so its recipe ignores "Used for" ticks and
 * each ingredient may sit on ONE line only. Raised when a product with an
 * ingredient on several lines (for different order types) is switched to
 * cooked, and when such lines are saved on a cooked product. A 422 in English
 * and Arabic with a code the portal maps to its own wording.
 */
final class CookedRecipeSplitLinesException extends LocalizedException
{
    public const CODE = 'cooked_split_lines';

    public const MESSAGE = 'Cooked products are made before orders exist, so each ingredient can have only one line. Merge the lines first.';

    public const MESSAGE_AR = 'المنتجات المطبوخة تُحضَّر قبل وجود الطلبات، لذا يمكن أن يكون لكل مكوّن سطر واحد فقط. ادمج الأسطر أولاً.';

    /** @param  list<string>  $ingredients  the names on several lines */
    public function __construct(public readonly array $ingredients = [])
    {
        parent::__construct(
            self::CODE,
            self::MESSAGE.($ingredients === [] ? '' : ' ('.implode(', ', $ingredients).')'),
            self::MESSAGE_AR.($ingredients === [] ? '' : ' ('.implode('، ', $ingredients).')'),
            ['ingredients' => $ingredients],
        );
    }
}
