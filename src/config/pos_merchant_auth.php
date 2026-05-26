<?php

declare(strict_types=1);

/**
 * Config namespace for merchant portal auth tunables. Mirrors
 * pos_admin's `pos_admin_auth.*` — same keys, different prefix.
 *
 * Every value is env-driven so deploys can tune them per
 * environment without code changes. The defaults are conservative
 * for a pilot:
 *   - 30-minute idle timeout means a coffee break logs you out
 *     (we re-evaluate when usage data tells us this is annoying)
 *   - 5 failed logins per (email, ip) per minute is enough to
 *     keep typo retries fast while blocking credential stuffing
 */
return [
    'session' => [
        'idle_timeout_minutes' => (int) env('POS_MERCHANT_IDLE_TIMEOUT_MINUTES', 30),
    ],

    'rate_limits' => [
        'login_per_minute' => (int) env('POS_MERCHANT_LOGIN_RATE_LIMIT_PER_MINUTE', 5),
    ],
];
