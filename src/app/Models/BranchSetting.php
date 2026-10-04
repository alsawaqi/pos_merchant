<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-branch merchant POS policy. Schema belongs to pos_admin; this portal
 * writes the rows and pos_api reads them with a company-default fallback.
 * A missing row means inherit. The JSON value may be a scalar string.
 */
class BranchSetting extends Model
{
    use BelongsToCompany;

    protected $table = 'pos_branch_settings';

    protected $guarded = [];

    public const KEY_DINE_IN_ROUND_MODE = 'dine_in_round_mode';

    /**
     * LAUNCH-P5 B6 — the shift-end reminder: "HH:MM" (Muscat) or null = off.
     * pos_api emits it as settings.shift_end_reminder_at.
     */
    public const KEY_SHIFT_END_REMINDER_AT = 'shift_end_reminder_at';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
