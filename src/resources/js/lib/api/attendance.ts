/**
 * LAUNCH-P5 B4 — correct a clock-in / clock-out from the Hours report.
 * Mirrors {@link \App\Http\Controllers\Pos\AttendanceController}. Times are
 * Muscat-local "Y-m-d H:i"; a reason is required (audited).
 */

import { apiPatch, type JsonValue } from '@/lib/api';

export interface UpdateAttendancePayload {
    clock_in_at: string;
    clock_out_at: string | null;
    reason: string;
}

export interface UpdateAttendanceResponse {
    data: { uuid: string; clock_in_local: string; clock_out_local: string | null; edit_reason: string | null };
}

export function updateAttendance(uuid: string, payload: UpdateAttendancePayload): Promise<UpdateAttendanceResponse> {
    return apiPatch<UpdateAttendanceResponse>(`/api/attendance/${uuid}`, payload as unknown as JsonValue);
}
