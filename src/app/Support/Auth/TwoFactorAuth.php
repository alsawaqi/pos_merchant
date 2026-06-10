<?php

declare(strict_types=1);

namespace App\Support\Auth;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin facade over the TOTP engine + QR renderer (Phase D8).
 *
 * Library picks (per the D8 recon):
 *   - pragmarx/google2fa — RFC 6238 (SHA-1, 6 digits, 30s period)
 *     + RFC 4648 base32 secret handling, with timing-safe compares
 *     internally and a ±1-period drift window for clock skew.
 *   - bacon/bacon-qr-code SvgImageBackEnd — emits pure SVG (needs
 *     ext-xmlwriter only). Deliberately NOT a gd-based renderer:
 *     the merchant container has no gd, and an inline SVG string
 *     embeds straight into the SPA anyway.
 *
 * Recovery codes are 10-char strings from an ambiguity-free
 * alphabet (no 0/O/1/I/L), displayed as XXXXX-XXXXX. Only their
 * SHA-256 hashes are persisted; matching strips formatting and
 * compares with hash_equals so neither a DB dump nor a timing
 * oracle leaks a usable code.
 */
final readonly class TwoFactorAuth
{
    /** TOTP codes accepted from the previous/current/next 30s period. */
    private const VERIFY_WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    /** Crockford-ish alphabet — no 0/O, 1/I/L lookalikes. */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    /** Fresh 32-char base32 secret (160 bits — RFC 4226 §4 minimum is 128). */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    /**
     * Verify a 6-digit TOTP code against the secret (window ±1
     * period). google2fa uses hash_equals internally, and its
     * timestamp bookkeeping prevents the trivial replay of the
     * exact same window — but we treat codes as replayable within
     * the window and rely on the endpoint throttle for brute force.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return $this->engine->verifyKey($secret, $code, self::VERIFY_WINDOW) !== false;
    }

    /**
     * The otpauth:// provisioning URI encoded into the QR
     * (issuer + account label per the de-facto Key Uri Format).
     */
    public function otpauthUrl(string $issuer, string $holder, string $secret): string
    {
        return $this->engine->getQRCodeUrl($issuer, $holder, $secret);
    }

    /**
     * Inline-embeddable SVG QR of the provisioning URI. The XML
     * declaration the renderer prepends is stripped so the string
     * can drop straight into the SPA's DOM.
     */
    public function qrSvg(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(192, 0),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($otpauthUrl);

        if (str_starts_with($svg, '<?xml')) {
            $svg = trim(substr($svg, (int) strpos($svg, '?>') + 2));
        }

        return $svg;
    }

    /**
     * Mint the one-time recovery codes. The PLAIN list is shown to
     * the user exactly once; only the HASHED list is persisted.
     *
     * @return array{plain: list<string>, hashed: list<string>}
     */
    public function generateRecoveryCodes(): array
    {
        $plain = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $plain[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return [
            'plain' => $plain,
            'hashed' => array_map(fn (string $code): string => $this->hashRecoveryCode($code), $plain),
        ];
    }

    /**
     * Find the index of the stored hash matching the supplied
     * recovery code, or null. Formatting (dashes, spaces, case) is
     * normalised away; the comparison itself is constant-time.
     *
     * @param  list<string>  $hashedCodes
     */
    public function matchRecoveryCode(array $hashedCodes, string $input): ?int
    {
        $candidate = $this->hashRecoveryCode($input);

        foreach ($hashedCodes as $index => $hash) {
            if (hash_equals($hash, $candidate)) {
                return $index;
            }
        }

        return null;
    }

    /** SHA-256 over the canonical (uppercase, alnum-only) form. */
    private function hashRecoveryCode(string $code): string
    {
        $canonical = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return hash('sha256', $canonical);
    }
}
