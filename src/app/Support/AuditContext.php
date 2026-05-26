<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Helper that resolves the current request's IP and user agent so
 * callers never have to thread them through manually. Returns nulls
 * in CLI / queue contexts. Mirrors pos_admin's AuditContext.
 */
final class AuditContext
{
    public static function ipAddress(): ?string
    {
        return self::request()?->ip();
    }

    public static function userAgent(): ?string
    {
        $agent = self::request()?->userAgent();

        if ($agent === null) {
            return null;
        }

        return mb_substr($agent, 0, 1024);
    }

    private static function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }
}
