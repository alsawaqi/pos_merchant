<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Targets;

use App\Enums\BranchTargetPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/branch-targets.
 *
 * window_periods caps at 60 (e.g. a 60-day or ~5-year-of-months window
 * is already beyond any sane evaluation horizon). starts_on may be in
 * the past — the lazy finalizer backfills the elapsed windows.
 */
class CreateBranchTargetRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_uuid' => ['required', 'string', 'uuid'],
            'period' => ['required', 'string', Rule::in(BranchTargetPeriod::values())],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'window_periods' => ['required', 'integer', 'min:1', 'max:60'],
            // Bounded both ways: an ancient anchor (a year-typo like 1026)
            // would queue a six-figure lazy backfill of window rows + SUM
            // queries; a far-future one is meaningless. One year each way.
            'starts_on' => [
                'required', 'date',
                'after_or_equal:'.now()->subYear()->toDateString(),
                'before_or_equal:'.now()->addYear()->toDateString(),
            ],
        ];
    }
}
