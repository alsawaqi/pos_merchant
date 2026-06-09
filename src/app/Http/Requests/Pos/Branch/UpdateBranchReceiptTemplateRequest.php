<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Branch;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for
 *   PUT /api/pos/branches/{branch:uuid}/receipt-template
 *
 * The merchant authors the receipt that the POS device prints for
 * THIS branch: business name (EN/AR), Commercial Registration (CR)
 * number, VAT number, address, phone, plus a few free header/footer
 * lines and two toggles. Everything is optional — a fully empty
 * template just means "fall back to the device's built-in default".
 *
 * The whole template is PUT as one object (not a partial patch);
 * the action normalizes it into the canonical stored shape.
 */
class UpdateBranchReceiptTemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['nullable', 'string', 'max:120'],
            'business_name_ar' => ['nullable', 'string', 'max:120'],
            'cr_number' => ['nullable', 'string', 'max:60'],
            'vat_number' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:280'],
            'phone' => ['nullable', 'string', 'max:40'],

            // Elements are `nullable` because Laravel's global TrimStrings +
            // ConvertEmptyStringsToNull middleware turn the UI's blank/
            // whitespace rows into null before validation; the action drops
            // them. A genuine over-long line still fails max:120.
            'header_lines' => ['nullable', 'array', 'max:6'],
            'header_lines.*' => ['nullable', 'string', 'max:120'],
            'footer_lines' => ['nullable', 'array', 'max:6'],
            'footer_lines.*' => ['nullable', 'string', 'max:120'],

            'show_qr' => ['sometimes', 'boolean'],

            // Logo: a base64-encoded PNG (no data: prefix). The browser resizes
            // + greyscales it to a receipt-friendly bitmap before upload, so the
            // server only validates it's a real, size-capped PNG (no GD needed).
            // ~200k chars ≈ 150 KB decoded — ample for a 384px monochrome logo.
            'logo_base64' => ['nullable', 'string', 'max:200000', $this->pngRule()],
        ];
    }

    /**
     * Closure rule: the value must base64-decode to bytes that start with the
     * 8-byte PNG signature. Empty/null is allowed (no logo).
     */
    private function pngRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $binary = base64_decode($value, true);
            if ($binary === false || strncmp($binary, "\x89PNG\r\n\x1a\n", 8) !== 0) {
                $fail('The logo must be a valid PNG image.');
            }
        };
    }
}
