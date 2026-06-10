/**
 * Phase B — shift management actions (Additions §1.2).
 *
 * The shift LIST lives in the Shift Report (lib/api/reports.ts —
 * fetchShiftReport). This module holds the mutations: the manager
 * re-open of a closed shift, allowed on the SAME business day only
 * (server-enforced), gated on orders.cancel, audited.
 */

import { apiPost, type JsonValue } from '@/lib/api';

export function reopenShift(uuid: string): Promise<{ data: { uuid: string; status: string } }> {
    return apiPost<{ data: { uuid: string; status: string } }>(
        `/api/shifts/${uuid}/reopen`,
        {} as JsonValue,
    );
}
