<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAUNCH-P4 B6 — the menu file for POST /api/products/import/preview and
 * /commit: an .xlsx or a CSV (UTF-8), up to 5 MB. The content is checked by
 * the reader (zip signature, encoding); here only the envelope.
 */
class MenuImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,csv,txt', 'max:5120'],
            'create_categories' => ['nullable', 'boolean'],
        ];
    }
}
