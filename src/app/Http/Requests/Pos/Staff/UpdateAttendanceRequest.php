<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Staff;

use App\Enums\MerchantPermission;
use App\Support\BusinessTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * LAUNCH-P5 B4 — PATCH /api/attendance/{attendance}: correct a clock-in /
 * clock-out. Times are Muscat-local "Y-m-d H:i" (the Hours report shows them
 * that way); a reason is required. The clock-out may be left empty (still
 * at work), must not be before the clock-in, and neither may be in the
 * future; a record longer than 24 hours is refused as a typing mistake.
 */
class UpdateAttendanceRequest extends FormRequest
{
    public const MAX_HOURS = 24;

    public function authorize(): bool
    {
        // staff.attendance.manage — checked before validation so a user
        // without it gets a 403, not field errors.
        return $this->user()?->can(MerchantPermission::StaffAttendanceManage->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clock_in_at' => ['required', 'date_format:Y-m-d H:i'],
            'clock_out_at' => ['present', 'nullable', 'date_format:Y-m-d H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $in = $this->clockIn();
            $out = $this->clockOut();
            $latest = Carbon::now()->addMinutes(5);
            if ($in->greaterThan($latest)) {
                $v->errors()->add('clock_in_at', 'The clock-in cannot be in the future.');
            }
            if ($out !== null) {
                if ($out->greaterThan($latest)) {
                    $v->errors()->add('clock_out_at', 'The clock-out cannot be in the future.');
                }
                if ($out->lessThan($in)) {
                    $v->errors()->add('clock_out_at', 'The clock-out cannot be before the clock-in.');
                } elseif ($in->diffInMinutes($out) > self::MAX_HOURS * 60) {
                    $v->errors()->add('clock_out_at', 'A clock-in and clock-out more than 24 hours apart looks like a mistake.');
                }
            }
        });
    }

    public function clockIn(): Carbon
    {
        return BusinessTime::fromLocal((string) $this->input('clock_in_at'));
    }

    public function clockOut(): ?Carbon
    {
        $value = $this->input('clock_out_at');

        return is_string($value) && $value !== '' ? BusinessTime::fromLocal($value) : null;
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
