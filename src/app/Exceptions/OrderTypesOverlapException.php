<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * LAUNCH packaging add-on (tester call 3) — the same item on two lines of one
 * product (or one add-on option and direction) whose "Used for" ticks
 * overlap. The DB refuses it too (one partial unique per tick bit); this is
 * the clean 422 the merchant sees first, in English and Arabic, with a code
 * the portal maps to its own wording.
 */
final class OrderTypesOverlapException extends LocalizedException
{
    public const CODE = 'order_types_overlap';

    public function __construct(
        public readonly string $item,
        public readonly string $types,
        public readonly string $typesAr,
    ) {
        parent::__construct(
            self::CODE,
            sprintf(
                'Duplicate line: "%s" is on more than one line for the same order type (%s). Lines of the same item must be used for different order types — or put the whole amount on one line.',
                $item,
                $types,
            ),
            sprintf(
                'سطر مكرر: "%s" موجود في أكثر من سطر لنفس نوع الطلب (%s). أسطر الصنف نفسه يجب أن تكون لأنواع طلب مختلفة — أو ضع الكمية كلها في سطر واحد.',
                $item,
                $typesAr,
            ),
            ['item' => $item],
        );
    }

    public function messageAr(): string
    {
        return $this->messageAr;
    }
}
