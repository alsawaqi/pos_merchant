<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\FloorPlan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 5.5 — bulk layout save.
 *
 *   POST /api/floors/{floor:uuid}/layout
 *   {
 *     "tables": [
 *       {"uuid": "...", "position_x": 100, "position_y": 200, "width": 80, "height": 80},
 *       {"uuid": "...", "position_x": 300, "position_y": 200}
 *     ]
 *   }
 *
 * Width / height are optional — when omitted we keep the
 * current row's values (a pure drag updates only x/y).
 *
 * Per-UUID cross-tenant validation happens in the Action so
 * the request can stay declarative + the Action can rely on
 * its own validated state.
 */
class SaveFloorLayoutRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tables' => ['required', 'array', 'min:1', 'max:500'],
            'tables.*.uuid' => ['required', 'string', 'uuid'],
            // 65535 mirrors the column upper bound so any
            // payload that would later fail the DB write
            // already fails here with a clean field error.
            'tables.*.position_x' => ['required', 'integer', 'between:0,65535'],
            'tables.*.position_y' => ['required', 'integer', 'between:0,65535'],
            'tables.*.width' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'tables.*.height' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
        ];
    }
}
