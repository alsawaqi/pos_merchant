<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

final class RuleValidity
{
    /**
     * Rule forms send Oman wall time with an explicit +04:00 offset.
     * Normalize before Eloquent writes to the UTC, timezone-less columns.
     * Legacy unzoned API values keep UTC, matching request validation.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalize(array $attributes): array
    {
        foreach (['validity_start', 'validity_end'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = CarbonImmutable::parse($attributes[$field], 'UTC')->utc();
            }
        }

        return $attributes;
    }
}
