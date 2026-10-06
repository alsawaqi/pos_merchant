<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * LAUNCH packaging add-on (fix order PK-B1, item 8) — a refusal the merchant
 * reads, in English AND Arabic: renders a 422 {message, message_ar, code,
 * …extra}. The portal shows message_ar when the page is in Arabic.
 *
 * Deliberately not a RuntimeException: the catalogue and inventory
 * controllers turn those into a plain English 422, and this one renders
 * itself, wherever it is thrown (inside a transaction it rolls it back).
 */
class LocalizedException extends DomainException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $messageAr,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'message_ar' => $this->messageAr,
            'code' => $this->errorCode,
        ] + $this->extra, 422);
    }
}
