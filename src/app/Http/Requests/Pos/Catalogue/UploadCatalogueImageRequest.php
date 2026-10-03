<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAUNCH-P4 B5 — POST /api/catalogue/images (multipart).
 *
 * The browser already resizes the photo (longest side 800 px, JPEG, about
 * 300 KB). The server still checks the real type from the file's content
 * (JPEG, PNG or WebP), the size (500 KB at most) and the dimensions (1600 px
 * at most per side), so an API caller cannot store anything else.
 */
class UploadCatalogueImageRequest extends FormRequest
{
    public const MAX_KB = 500;

    public const MAX_SIDE = 1600;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.self::MAX_KB,
                'dimensions:max_width='.self::MAX_SIDE.',max_height='.self::MAX_SIDE,
            ],
            'kind' => ['nullable', 'string', 'in:product,category'],
        ];
    }
}
