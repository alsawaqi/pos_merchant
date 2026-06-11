<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * Decrypt `encrypted` casts defensively.
 *
 * pos_admin and pos_merchant share one database AND one APP_KEY; if the keys
 * ever drift, every page touching a row with foreign-key ciphertext 500s with
 * "The MAC is invalid" (observed in production on the admin Portal Users tab).
 * A display field is never worth a dead page: surface NULL instead, and log a
 * warning as the breadcrumb — the key drift itself still has to be fixed
 * operationally (align APP_KEY across both portals; NEVER key:generate).
 */
trait DecryptsDefensively
{
    /**
     * @param  string  $value
     */
    public function fromEncryptedString($value): mixed
    {
        try {
            return parent::fromEncryptedString($value);
        } catch (DecryptException) {
            Log::warning('Undecryptable ciphertext (APP_KEY drift between portals?)', [
                'model' => static::class,
                'key' => $this->getKey(),
            ]);

            return null;
        }
    }
}
