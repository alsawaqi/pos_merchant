<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Staff;

use App\Enums\MerchantPermission;
use App\Models\StaffAttendance;
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
 *
 * Fix order 1, L6 — the corrected record must not overlap another record of
 * the same person (that would count the same hours twice), and it may only
 * be left open (no clock-out) when no other record of that person is open
 * (pos_api allows one open record per person). Touching records
 * (12:00–13:00 and 13:00–17:00) do not overlap.
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
            if ($v->errors()->isEmpty()) {
                $this->checkOtherRecords($v, $in, $out);
            }
        });
    }

    /** L6 — no overlap with, and no second open record beside, the person's other records. */
    private function checkOtherRecords(Validator $v, Carbon $in, ?Carbon $out): void
    {
        $attendance = $this->route('attendance');
        if (! $attendance instanceof StaffAttendance) {
            return;
        }
        $others = StaffAttendance::query()
            ->withoutGlobalScopes()
            ->where('company_id', $attendance->company_id)
            ->where('staff_id', $attendance->staff_id)
            ->where('id', '!=', $attendance->id);

        if ($out === null && (clone $others)->whereNull('clock_out_at')->exists()) {
            $v->errors()->add('clock_out_at', 'This person has another record that is still open; give this one a clock-out.');

            return;
        }

        // [in, out) overlaps [other_in, other_out) when other_in < out and
        // (other_out is open or other_out > in); an open record runs on.
        $overlap = (clone $others)
            ->when($out !== null, fn ($q) => $q->where('clock_in_at', '<', $out))
            ->where(fn ($q) => $q->whereNull('clock_out_at')->orWhere('clock_out_at', '>', $in))
            ->orderBy('clock_in_at')
            ->first();
        if ($overlap !== null) {
            $v->errors()->add('clock_in_at', sprintf(
                'These times overlap another record of the same person (%s to %s).',
                BusinessTime::local($overlap->clock_in_at),
                BusinessTime::local($overlap->clock_out_at) ?? 'still open',
            ));
        }
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
