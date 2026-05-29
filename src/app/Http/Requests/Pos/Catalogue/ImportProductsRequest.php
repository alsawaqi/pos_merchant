<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 6b-import — bulk product import upload validation.
 *
 * Only the envelope (the uploaded file) is validated here; per-ROW validation
 * happens in ImportProductsAction so one bad row reports an error instead of
 * rejecting the whole file. catalogue.manage is enforced on the route.
 */
class ImportProductsRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }
}
