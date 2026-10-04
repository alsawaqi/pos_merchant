<?php

declare(strict_types=1);

namespace App\Support;

/**
 * LAUNCH-P5 — the offline approver verifier (work order "Approval proof").
 *
 * Whenever the server holds a plaintext PIN (here: PIN mint and reset) it
 * stores, on pos_staff:
 *
 *   pin_offline_salt       = 16 random bytes, lowercase hex
 *   pin_offline_iterations = config pos.approver_kdf_iterations (100000)
 *   pin_offline_key        = K = PBKDF2-HMAC-SHA256(PIN as UTF-8, salt bytes,
 *                            iterations, 32 bytes), stored as lowercase hex
 *
 * pos_api gives devices {salt, iterations, check} per approver, where
 * check = hex(SHA-256("mithqal-approver-check-v1" ‖ K)), and verifies each
 * offline approval's HMAC proof with K. K itself never leaves the server and
 * is never logged (the columns are hidden on the model). The golden vectors
 * in tests/Fixtures/launch-p5/approval_proof_goldens.json pin the algorithm.
 */
final class ApproverVerifier
{
    public const CHECK_PREFIX = 'mithqal-approver-check-v1';

    public const DEFAULT_ITERATIONS = 100000;

    /** K, raw 32 bytes. */
    public static function deriveKey(string $pin, string $saltBytes, int $iterations): string
    {
        return hash_pbkdf2('sha256', $pin, $saltBytes, $iterations, 32, true);
    }

    /** hex(SHA-256("mithqal-approver-check-v1" ‖ K)) — what a device compares. */
    public static function check(string $key): string
    {
        return hash('sha256', self::CHECK_PREFIX.$key);
    }

    /** The configured iteration count (a positive integer; the default otherwise). */
    public static function iterations(): int
    {
        $configured = config('pos.approver_kdf_iterations', self::DEFAULT_ITERATIONS);

        return is_numeric($configured) && (int) $configured >= 1 ? (int) $configured : self::DEFAULT_ITERATIONS;
    }

    /**
     * Fresh verifier material for a PIN, keyed by the pos_staff column names.
     *
     * @return array{pin_offline_key: string, pin_offline_salt: string, pin_offline_iterations: int}
     */
    public static function make(string $pin): array
    {
        $salt = random_bytes(16);
        $iterations = self::iterations();

        return [
            'pin_offline_key' => bin2hex(self::deriveKey($pin, $salt, $iterations)),
            'pin_offline_salt' => bin2hex($salt),
            'pin_offline_iterations' => $iterations,
        ];
    }
}
