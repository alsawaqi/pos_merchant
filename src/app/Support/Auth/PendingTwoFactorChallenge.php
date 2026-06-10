<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Server-side state for the half-completed login of a 2FA-enrolled
 * user (Phase D8).
 *
 * After the password check passes, the login controller does NOT
 * authenticate — it parks {user id, remember-me intent, expiry}
 * here and bounces the browser to /two-factor-challenge. Only the
 * challenge endpoint can convert this pending state into a real
 * session, so a stolen password alone never yields auth.
 *
 * The state is deliberately short-lived (config
 * pos_merchant_auth.two_factor.challenge_ttl_minutes, default 5):
 * an abandoned challenge page goes stale and the user restarts
 * from /login.
 */
final readonly class PendingTwoFactorChallenge
{
    private const KEY_USER_ID = 'pos_merchant.two_factor.pending_user_id';

    private const KEY_REMEMBER = 'pos_merchant.two_factor.remember';

    private const KEY_EXPIRES_AT = 'pos_merchant.two_factor.expires_at';

    /**
     * Park the challenge. Regenerates the session id FIRST so the
     * pending state never lives in a session id that predates the
     * password check (anti-fixation — same reason the normal login
     * regenerates).
     */
    public static function begin(Session $session, User $user, bool $remember): void
    {
        $session->regenerate();

        $ttlMinutes = max(1, (int) config('pos_merchant_auth.two_factor.challenge_ttl_minutes', 5));

        $session->put(self::KEY_USER_ID, (int) $user->id);
        $session->put(self::KEY_REMEMBER, $remember);
        $session->put(self::KEY_EXPIRES_AT, now()->addMinutes($ttlMinutes)->timestamp);
    }

    /**
     * The pending user id, or null when nothing is pending / the
     * challenge expired (expired state is wiped on read).
     */
    public static function userId(Session $session): ?int
    {
        $userId = $session->get(self::KEY_USER_ID);
        $expiresAt = (int) $session->get(self::KEY_EXPIRES_AT, 0);

        if ($userId === null) {
            return null;
        }

        if ($expiresAt < now()->timestamp) {
            self::clear($session);

            return null;
        }

        return (int) $userId;
    }

    public static function remember(Session $session): bool
    {
        return (bool) $session->get(self::KEY_REMEMBER, false);
    }

    public static function clear(Session $session): void
    {
        $session->forget([self::KEY_USER_ID, self::KEY_REMEMBER, self::KEY_EXPIRES_AT]);
    }
}
