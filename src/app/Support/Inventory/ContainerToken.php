<?php

declare(strict_types=1);

namespace App\Support\Inventory;

/**
 * LAUNCH review add-on (A2) — the token that names ONE container on the wire
 * wherever a unit comes in (recipe lines, purchases, transfers, waste,
 * counts, restock requests, the warehouse dialog).
 *
 * Names are no longer unique ("bottle 1.5 l" and "bottle 500 ml"), so a
 * container is named by its uuid. The token is "#" + the uuid's 16 bytes in
 * base64url (23 characters): the recipe tables keep the entered unit in a
 * varchar(32) column, which "#" + a 36-character uuid would not fit. The
 * long form "#<uuid>" is accepted on input too and means the same container.
 *
 * '#' and '@' never start a container NAME ({@see PackSize::nameProblem()}),
 * so a token can never be mistaken for a name.
 */
final class ContainerToken
{
    public const PREFIX = '#';

    public static function encode(string $uuid): string
    {
        $hex = str_replace('-', '', strtolower($uuid));
        $bytes = hex2bin($hex);
        if ($bytes === false || strlen($bytes) !== 16) {
            return self::PREFIX.$uuid;
        }

        return self::PREFIX.rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Whether a unit string is a container token (either form). */
    public static function isToken(?string $unit): bool
    {
        return $unit !== null && str_starts_with($unit, self::PREFIX) && self::decode($unit) !== null;
    }

    /** The uuid a token names, or null when it is not a token. */
    public static function decode(?string $token): ?string
    {
        if ($token === null || ! str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $body = substr($token, 1);
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $body) === 1) {
            return strtolower($body);
        }
        if (preg_match('/^[A-Za-z0-9_-]{22}$/', $body) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($body, '-_', '+/').'==', true);
        if ($bytes === false || strlen($bytes) !== 16) {
            return null;
        }
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
