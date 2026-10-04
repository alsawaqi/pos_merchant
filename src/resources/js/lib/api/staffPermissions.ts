/**
 * LAUNCH-P5 B1 — staff permissions (tick list per position).
 *
 * Mirrors {@link \App\Http\Controllers\Pos\StaffPermissionsController}.
 * GET returns the resolved matrix (defaults filled in), the defaults, and the
 * fixed positions / actions; PUT takes only the changed cells.
 */

import { apiGet, apiPut, type JsonValue } from '@/lib/api';
import type { PositionMatrix, PositionMatrixChanges } from '@/lib/staffPermissions';

export interface StaffPermissionsPayload {
    positions: string[];
    actions: string[];
    always_on: Record<string, string[]>;
    permissions: PositionMatrix;
    defaults: PositionMatrix;
}

export function getStaffPermissions(): Promise<{ data: StaffPermissionsPayload }> {
    return apiGet<{ data: StaffPermissionsPayload }>('/api/settings/staff-permissions');
}

export function updateStaffPermissions(
    changes: PositionMatrixChanges,
): Promise<{ data: StaffPermissionsPayload }> {
    return apiPut<{ data: StaffPermissionsPayload }>(
        '/api/settings/staff-permissions',
        { permissions: changes } as unknown as JsonValue,
    );
}
